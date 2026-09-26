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
        $data = $request->validate(['conversation_id' => 'required|uuid']);
        $messages = DB::table('markai_messages')->where('user_id', $request->user()->id)
            ->where('conversation_id', $data['conversation_id'])->where('status', 'complete')
            ->orderByDesc('created_at')->limit(100)->get()->reverse()->values()->map(fn ($row) => [
                'id' => $row->id, 'prompt' => $row->prompt, 'reply' => json_decode($row->reply, true),
            ]);

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request, Markai $ai)
    {
        $data = $request->validate(['id' => 'required|uuid', 'conversation_id' => 'required|uuid',
            'mode' => ['required', Rule::in(['macros', 'training', 'free'])], 'prompt' => 'required|string|max:6000']);
        $user = $request->user();
        $previous = DB::table('markai_messages')->where('id', $data['id'])->first();
        if ($previous) {
            abort_unless($previous->user_id === $user->id && $previous->prompt === $data['prompt']
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
        $confirmation = preg_match('/^(?:ok(?:ay)?[,!. ]*)?(?:let[’\x27]?s log(?: it)?|log(?: it| this| that)?|save(?: it| this)?)[.! ]*$/iu', trim($data['prompt'])) === 1;
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
                    $messages[] = ['role' => 'user', 'content' => $row->prompt];
                    $messages[] = ['role' => 'assistant', 'content' => $row->reply];
                }
                $messages[] = ['role' => 'user', 'content' => $data['prompt']];
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
