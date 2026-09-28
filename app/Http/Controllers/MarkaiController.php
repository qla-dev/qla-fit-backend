<?php

namespace App\Http\Controllers;

use App\Services\Markai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MarkaiController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['conversation_id' => 'sometimes|uuid']);
        if (! isset($data['conversation_id'])) {
            $threads = DB::table('markai_messages as messages')
                ->where('messages.user_id', $request->user()->id)->where('messages.status', 'complete')
                ->select('messages.conversation_id', 'messages.mode')
                ->selectRaw('MAX(messages.created_at) as updated_at, COUNT(*) as message_count')
                ->selectSub(DB::table('markai_messages as first_message')->select('prompt')
                    ->whereColumn('first_message.conversation_id', 'messages.conversation_id')
                    ->where('first_message.user_id', $request->user()->id)->where('first_message.status', 'complete')
                    ->orderBy('created_at')->orderBy('id')->limit(1), 'title')
                ->groupBy('messages.conversation_id', 'messages.mode')->orderByDesc('updated_at')->limit(100)->get();

            return response()->json(['data' => $threads]);
        }
        $messages = DB::table('markai_messages')->where('user_id', $request->user()->id)
            ->where('conversation_id', $data['conversation_id'])->where('status', 'complete')
            ->orderByDesc('created_at')->limit(100)->get()->reverse()->values()->map(fn ($row) => [
                'id' => $row->id, 'prompt' => $row->prompt, 'has_image' => (bool) $row->has_image,
                'reply' => json_decode($row->reply, true),
            ]);

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request, Markai $ai)
    {
        $data = $request->validate(['id' => 'required|uuid', 'conversation_id' => 'required|uuid',
            'mode' => ['required', Rule::in(['macros', 'training', 'free'])],
            'prompt' => 'nullable|required_without:image|string|max:6000',
            // A downscaled JPEG from the app, forwarded to the model and never stored.
            'image' => ['nullable', 'string', 'max:4000000', 'regex:#^data:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}$#']]);
        $image = $data['image'] ?? null;
        unset($data['image']);
        $data['prompt'] ??= '';
        $data['has_image'] = $image !== null;
        $user = $request->user();
        $previous = DB::table('markai_messages')->where('id', $data['id'])->first();
        if ($previous) {
            abort_unless($previous->user_id === $user->id && $previous->prompt === $data['prompt']
                && (bool) $previous->has_image === $data['has_image']
                && $previous->conversation_id === $data['conversation_id'] && $previous->mode === $data['mode'], 409);
            abort_unless($previous->status === 'complete', 409, 'This message is pending or failed. Start a new request.');

            return response()->json(['data' => ['id' => $previous->id, 'reply' => json_decode($previous->reply, true), 'ai_coins' => $user->ai_coins]]);
        }
        $history = DB::table('markai_messages')->where('user_id', $user->id)
            ->where('conversation_id', $data['conversation_id'])->where('mode', $data['mode'])
            ->where('status', 'complete')->orderByDesc('created_at')->limit(12)->get()->reverse()->values();
        $last = $history->last();
        $lastReply = $last ? json_decode($last->reply, true) : null;
        // Confirmation is determined by application code, never an AI-provided action.
        $confirmation = ! $image && preg_match('/^(?:ok(?:ay)?[,!. ]*)?(?:let[’\x27]?s log(?: it)?|log(?: it| this| that)?|save(?: it| this)?)[.! ]*$/iu', trim($data['prompt'])) === 1;
        $food = $confirmation ? ($lastReply['food'] ?? null) : null;
        $cost = $food ? 0 : 1;
        DB::transaction(function () use ($user, $data, $cost) {
            if ($cost) {
                abort_unless(DB::table('users')->where('id', $user->id)->where('ai_coins', '>=', $cost)->decrement('ai_coins', $cost), 402, 'No AI coins remaining.');
            }
            DB::table('markai_messages')->insert([...$data, 'user_id' => $user->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            if ($food) {
                $reply = ['text' => 'Ready to log the food shown below.', 'food' => $food,
                    'food_id' => $lastReply['food_id'] ?? $last->id, 'log_requested' => true];
            } else {
                $messages = [];
                foreach ($history as $row) {
                    $messages[] = ['role' => 'user', 'content' => $row->has_image ? trim('[The user sent a photo.] '.$row->prompt) : $row->prompt];
                    $messages[] = ['role' => 'assistant', 'content' => $row->reply];
                }
                $messages[] = ['role' => 'user', 'content' => $image
                    ? [['type' => 'text', 'text' => $data['prompt'] !== '' ? $data['prompt'] : 'What is in this photo?'],
                        ['type' => 'image_url', 'image_url' => ['url' => $image]]]
                    : $data['prompt']];
                $reply = [...$ai->reply($data['mode'], $messages), 'food_id' => $data['id'], 'log_requested' => false];
            }
            DB::table('markai_messages')->where('id', $data['id'])->update(['status' => 'complete', 'reply' => json_encode($reply), 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::transaction(function () use ($data, $user, $cost) {
                if (DB::table('markai_messages')->where('id', $data['id'])->where('status', 'pending')->update(['status' => 'failed'])) {
                    DB::table('users')->where('id', $user->id)->increment('ai_coins', $cost);
                }
            });
            report($error);
            abort(503, 'MarkAI could not answer. Your coin was refunded.');
        }

        return response()->json(['data' => ['id' => $data['id'], 'reply' => $reply, 'ai_coins' => (int) $user->fresh()->ai_coins]]);
    }
}
