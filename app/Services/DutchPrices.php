<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dutch grocery prices from Checkjebon (github.com/supermarkt/checkjebon,
 * MIT licence), which collects the online assortment of the big Dutch
 * supermarkets (AH, Jumbo, Plus, Dirk, Lidl, SPAR, …) into one JSON file:
 * [{"n": chain, "d": [{"n": name, "p": price, "s": size, "l": link}, …]}].
 * There are no barcodes, so baskets carry no EAN.
 *
 * Once a day the file (about 10 MB) is downloaded and read a product at a
 * time rather than decoded whole, which would take some 70 MB. Its date is
 * that of the last commit that changed it.
 */
class DutchPrices extends ShelfPrices
{
    public const SOURCE = 'checkjebon';

    public const CURRENCY = 'EUR';

    public const COUNTRY = 'Dutch';

    public const DATA_URL = 'https://raw.githubusercontent.com/supermarkt/checkjebon/main/data/supermarkets.json';

    /** The data file's latest commit, for the date its prices are from. */
    public const COMMITS_URL = 'https://api.github.com/repos/supermarkt/checkjebon/commits';

    protected const NAMES = ['ah' => 'Albert Heijn', 'aldi' => 'ALDI', 'dekamarkt' => 'DekaMarkt', 'dirk' => 'Dirk',
        'ekoplaza' => 'Ekoplaza', 'hoogvliet' => 'Hoogvliet', 'jumbo' => 'Jumbo', 'lidl' => 'Lidl', 'plus' => 'PLUS',
        'poiesz' => 'Poiesz', 'spar' => 'SPAR', 'vomar' => 'Vomar'];

    /**
     * [english => [terms, exclusions, units]]. Dutch names lead with the
     * brand ("AH Halfvolle melk", "1 de Beste Kipfilet"), so a product counts
     * when a term appears anywhere as whole words (accents folded), no
     * exclusion appears in it, and it is sold by one of the staple's units:
     * sugar by the litre is a soft drink "0% suiker", not sugar.
     */
    public const STAPLES = [
        'chicken breast' => [['kipfilet', 'kipfilets', 'kippenfilet', 'scharrelkipfilet', 'kippenborstfilet'], ['gerookt', 'gebraden', 'gegrild', 'gekookt', 'beleg', 'flinterdun', 'krokant', 'gepaneerd', 'gemarineerd', 'salade', 'spread', 'soep', 'saus', 'met ', 'pasta', 'wrap', 'maaltijd', 'voer', 'snack', 'sate', 'shoarma', 'tandoori', 'kebab', 'pulled', 'plakjes', 'naturel 1', 'aldelis'], ['kg']],
        'minced beef' => [['rundergehakt', 'rundgehakt', 'runderrulgehakt'], ['bal', 'burger', 'saus', 'kruiden', 'gekruid', 'maaltijd', 'met ', 'lasagne', 'pasta'], ['kg']],
        'pork loin' => [['karbonade', 'karbonades', 'varkenshaas', 'haaskarbonade', 'haaskarbonades', 'schouderkarbonade', 'schouderkarbonades'], ['gekruid', 'gemarineerd', 'gerookt', 'saus', 'beleg', 'gebraden', 'met '], ['kg']],
        'eggs' => [['eieren', 'scharreleieren', 'uitloopeieren'], ['kwartel', 'gekookt', 'chocola', 'paas', 'mayo', 'salade', 'pasta', 'noedel', 'eiwit', 'spread', 'gepeld', 'verf'], ['piece']],
        'milk' => [['halfvolle melk', 'volle melk', 'magere melk', 'melk'], ['chocola', 'koffie', 'kokos', 'haver', 'soja', 'amandel', 'rijst', 'poeder', 'karnemelk', 'yoghurt', 'drink', 'baby', 'opvolg', 'vla', 'kwark', 'toffee', 'condens', 'geit', 'zeep', 'body', 'reinig', 'douche', 'lotion', 'cacao', 'aardbei', 'vanille', 'shake', 'chocomel', 'fristi', 'melkbrood', 'melkchocolade', 'ijs'], ['l']],
        'yogurt' => [['yoghurt', 'magere yoghurt', 'volle yoghurt', 'halfvolle yoghurt'], ['grieks', 'drink', 'fruit', 'aardbei', 'vanille', 'framboos', 'perzik', 'kers', 'muesli', 'granola', 'chocola', 'skyr', 'soja', 'kokos', 'haver', 'ijs', 'dressing', 'saus', 'honing', 'mango', 'bosvruchten', 'protein', 'limoen', 'citroen', 'kids', 'smoothie', ' bar', 'rozijn', 'kaneel', 'banaan', 'appel', 'abrikoos', 'stracciatella', 'kwark', 'toetje'], ['l', 'kg']],
        'cottage cheese' => [['huttenkase', 'cottage cheese'], ['bieslook', 'ananas', 'kruiden', 'tuinkruiden'], ['kg']],
        'cheese' => [['kaas', 'gouda', 'goudse'], ['plak', 'geraspt', 'smeer', 'room', 'spread', 'saus', 'souffle', 'stengel', 'koek', 'cracker', 'chips', 'tortilla', 'pizza', 'broodje', 'vegan', 'vegetarisch', 'fondue', 'salade', 'burger', 'croissant', 'pasta', 'macaroni', 'kroket', 'bitterbal', 'tosti', 'hond', 'kat', 'feta', 'mozzarella', 'brie', 'camembert', 'parmez', 'cheddar', 'geit', 'schapen', 'zwitser', 'snack', 'bites', 'kaasschaaf', 'rasp', 'dip', 'blokjes', 'hapjes', 'stukjes', 'mini', 'sticks', 'sandwich', 'wrap', 'quiche', 'gratin'], ['kg']],
        'butter' => [['roomboter', 'boter'], ['pinda', 'hazelnoot', 'amandel', 'cashew', 'koek', 'croissant', 'cake', 'plant', 'halvarine', 'margarine', 'bak', 'braad', 'kruiden', 'knoflook', 'vloeibaar', 'zeep', 'cacao', 'shea', 'body', 'bonen', 'bladerdeeg', 'sprits', 'boterham', 'wafel', 'zout', 'koekjes', 'letter'], ['kg']],
        'olive oil' => [['olijfolie'], ['spray', 'tonijn', 'in olijfolie', 'met olijfolie', 'sardine', 'zeep', 'chips', 'tapenade', 'pesto', 'olijven', 'cracker', 'brood', 'ansjovis', 'makreel', 'artisjok', 'tomaten', 'kruiden', 'knoflook', 'chili', 'citroen', 'truffel', 'basilicum', 'body', 'haar', 'mayo'], ['l']],
        'rice' => [['rijst', 'zilvervliesrijst', 'basmatirijst', 'pandanrijst', 'jasmijnrijst', 'witte rijst', 'langgraanrijst'], ['wafel', 'cracker', 'melk', 'drink', 'pap', 'noedel', 'vermicelli', 'koek', 'maaltijd', 'nasi', 'gebakken', 'met ', 'mix', 'kip', 'saus', 'salade', 'pudding', 'dessert', 'chips', 'bloem', 'azijn', 'pasta', 'risotto', 'paella', 'sushi', 'zilvervliesrijstwafel', 'rijstwafel', 'snack', 'magnetron', 'kant', 'klaar', 'curry', 'surinaams', 'wok', 'bami', 'mexicaans', 'spaans'], ['kg']],
        'pasta' => [['spaghetti', 'penne', 'macaroni', 'fusilli', 'pasta', 'farfalle', 'tagliatelle', 'linguine', 'rigatoni'], ['saus', 'salade', 'maaltijd', 'met ', 'pesto', 'kant', 'klaar', 'gevuld', 'tortellini', 'ravioli', 'lasagne', 'soep', 'vers', 'kip', 'gehakt', 'bolognese', 'carbonara', 'kaas', 'pastasaus', 'mix', 'snack', 'chips', 'zalm', 'tonijn', 'garnalen', 'groente', 'pastasalade', 'pastamaaltijd', 'roerbak', 'magnetron', 'kruiden', 'glutenvrij'], ['kg']],
        'oats' => [['havermout', 'havervlokken'], ['chocola', 'koek', 'reep', 'repen', 'drink', 'instant', ' cup', 'fruit', 'appel', 'kaneel', 'banaan', 'overnight', 'vla', ' bar', 'muesli', 'pap', 'protein', 'aardbei', 'bosvrucht', 'cracker', 'snack', 'smaak'], ['kg']],
        'bread' => [['brood', 'volkorenbrood', 'tarwebrood', 'bruinbrood', 'witbrood', 'casinobrood', 'tijgerbrood', 'meergranenbrood', 'volkoren tarwe'], ['krent', 'rozijn', 'mais', 'paas', 'kerst', 'suiker', 'ontbijtkoek', 'kruiden', 'knoflook', 'pizza', 'beschuit', 'mix', 'beleg', 'pinda', 'toast', 'glutenvrij', 'hond', 'paneermeel', 'kruim', 'vlok', 'stokbrood', 'pita', 'naan', 'wrap', 'tortilla', 'afbak', 'mini', 'broodjes', 'bolletjes', 'tosti', 'chips', 'cracker', 'spread', 'smeer', 'brioche', 'melkbrood', 'rogge', 'spelt', 'zuurdesem', 'bloem', 'meel', 'bakmix', 'broodmix', 'beleg', 'trommel', ' zak', 'snijder', 'plank'], ['kg', 'piece']],
        'flour' => [['tarwebloem', 'patentbloem', 'bloem', 'tarwemeel'], ['mais', 'rijst', 'amandel', 'kokos', 'mix', 'pannenkoek', 'poffertje', 'cake', 'brood', 'paneer', 'kool', 'zonne', 'snij', 'bos', 'boeket', 'bolletje', 'zaad', 'vers', 'plant', ' pot', 'tulp', 'roos', 'zelfrijzend', 'spelt', 'boekweit', 'kikkererwt', 'volkoren'], ['kg']],
        'potatoes' => [['aardappelen', 'aardappels', 'kruimige aardappelen', 'vastkokende aardappelen', 'krieltjes', 'kriel'], ['zoete', 'gekookt', 'salade', 'puree', 'partjes', 'schijfjes', 'frites', 'chips', 'kroket', 'gratin', 'rosti', 'met ', 'gebakken', 'oven', 'soep', 'maaltijd', 'stamp', 'schotel', 'gehakt', 'kaas', 'spinazie', 'bloemkool', 'bonen', 'wedges', 'kruiden', 'gesneden', 'geschild', 'roomboter', 'krieltjes in', 'gekruid', 'airfryer', 'rozemarijn', 'parten'], ['kg']],
        'onions' => [['uien', ' ui ', 'gele uien', 'rode uien'], ['gebakken', 'gefrituurd', 'crispy', 'snipper', 'poeder', 'soep', 'zilver', 'amsterdamse', 'ringen', 'chips', 'gesnipperd', 'zuur', 'vlokken', 'saus', 'marmelade', 'confit', 'lente', 'bosui', 'gedroogd', 'knoflook', 'paprika', 'chutney', 'kruiden', 'mix', 'met ', 'burger', 'worst', 'kaas', 'brood', 'gehakt', 'pizza', 'taart', 'quiche', 'creme', 'dip', 'relish', 'rode ui ', 'gesneden', 'mais', 'tomaat'], ['kg', 'piece']],
        'garlic' => [['knoflook'], ['poeder', 'granulaat', 'saus', 'olie', 'boter', 'pasta', 'brood', 'kaas', 'pers', 'zout', 'gedroogd', 'gerookt', 'peper', 'gemalen', 'creme', 'dip', 'chips', 'mayo', 'geroosterd', 'kruiden', 'gehakt', 'puree', 'gember', 'chili', ' ui ', 'tuin', 'pesto', 'spread', 'smaak', 'worst', 'met ', 'gesneden', 'garnalen', 'olijven', 'champignons', 'stokbrood', 'baguette', 'kip', 'snack', 'naan', 'baguette', 'baquette', 'friteslijn', 'hot', 'grissini', 'roerbak', 'pangasius', 'sambal', 'gamba', 'fuet', 'molen', 'korrels', 'vlees', 'aardappel', 'zwarte'], ['piece', 'kg']],
        'tomatoes' => [['tomaten', 'tomaat', 'trostomaten', 'cherrytomaten', 'snoeptomaten', 'pruimtomaten', 'vleestomaten', 'roma tomaten'], ['gezeefd', 'gepeld', 'blokjes', 'puree', 'saus', 'ketchup', 'soep', 'gedroogd', 'pasta', 'sap', 'frito', 'passata', 'blik', 'tapenade', 'salsa', 'pesto', 'concentr', 'stukjes', 'met ', 'kruiden', 'olie', 'chips', 'mozzarella', 'salade', 'pizza', 'gehakt', 'tomatenpuree', 'basilicum', 'knoflook', 'paprika', ' ui ', 'cracker', 'smaak', 'spread', 'dip', 'mix', 'cocktail', 'drink', 'juice'], ['kg', 'piece']],
        'peppers' => [['paprika', 'paprikas', 'puntpaprika', 'puntpaprikas', 'rode paprika', 'groene paprika', 'gele paprika'], ['poeder', 'chips', 'saus', 'gerookt', 'gegrild', 'gevuld', 'geroosterd', 'olie', 'zuur', 'pasta', 'spread', 'tapenade', 'kruiden', 'smaak', 'mix', 'ketchup', 'hummus', 'salade', 'pesto', 'dip', 'soep', 'tomaten', 'gemalen', 'gedroogd', 'worst', 'kaas', 'kip', 'zalm', 'cracker', 'met ', 'snack', ' ui ', 'mais', 'pizza', 'reepjes', 'gesneden', 'blokjes', 'diepvries', 'kipfilet', 'chili', 'zoete paprika', 'spek', ' ham', 'cashew', 'pinda', 'noten', 'crisps', 'borrelnoot', 'noot', 'lay', 'pringles', 'doritos', 'croky', 'smiths', 'bugles', 'nibb'], ['kg', 'piece']],
        'cucumber' => [['komkommer', 'komkommers', 'snackkomkommers', 'minikomkommers'], ['zuur', 'augurk', 'salade', 'tzatziki', 'dip', 'water', 'drink', 'sap', ' gel', 'zeep', 'masker', 'munt', 'limoen', 'citroen', 'thee', 'gin', 'tonic', 'spread', 'smaak', 'met ', 'mix', 'gesneden', 'body', 'douche', 'creme', 'rauwkost'], ['piece', 'kg']],
        'carrots' => [['wortelen', 'wortels', 'winterpeen', 'peen', 'bospeen', 'waspeen', 'worteltjes', 'peentjes'], ['sap', 'salade', 'rauwkost', 'soep', 'geraspt', 'julienne', 'erwten', 'hutspot', 'stamppot', 'met ', 'blik', 'glas', 'taart', 'cake', 'muffin', 'puree', 'baby', 'smoothie', 'chips', 'diepvries', 'gesneden', 'reepjes', 'blokjes', 'spread', 'hummus', 'koek', 'mix', 'gemberwortel', 'zoet', 'kitchen'], ['kg']],
        'cabbage' => [['witte kool', 'spitskool', 'savooiekool', 'kool', 'rode kool', 'groene kool', 'wittekool', 'rodekool'], ['zuurkool', 'salade', 'rauwkost', 'appel', 'stamppot', 'chinese', 'bloemkool', 'boerenkool', 'spruit', 'koolrabi', 'koolzaad', 'koolzuur', 'houtskool', 'met ', 'glas', ' pot', 'zoetzuur', 'kimchi', 'olie', 'gesneden', 'gestoofd', 'zalm', 'koolhydraat', 'koolvis', 'koolsla', 'spitskoolsalade', 'mix', 'blik', 'diepvries', 'gekookt', 'kruiden', 'snijbonen', 'potje'], ['kg', 'piece']],
        'lettuce' => [['ijsbergsla', 'sla', 'kropsla', 'botersla', 'romaine', 'romainesla', 'eikenbladsla', 'krulsla', 'little gem'], ['salade', 'dressing', 'mix', 'melange', 'maaltijd', 'met ', 'kip', 'tonijn', 'pasta', 'zalm', ' ei ', 'wrap', 'saus', 'olie', 'kaas', 'spek', ' ham', 'feta', 'burger', 'croutons', 'rucola', 'veldsla', 'sla-saus', 'slasaus', 'gesneden', 'snack', 'pakket', 'gemengd', 'veggie', 'caesar', 'griekse'], ['piece', 'kg']],
        'spinach' => [['spinazie', 'bladspinazie', 'babyspinazie'], ['creme', 'room', 'diepvries', 'gehakte', 'soep', 'pasta', 'ravioli', 'tortellini', 'quiche', 'pizza', 'wrap', 'smoothie', 'mix', 'kaas', 'feta', 'ricotta', 'puree', 'gestoofd', 'zalm', 'lasagne', 'a la', 'met ', 'portie', 'blokjes', 'ijs', 'burger', 'taart', 'cannelloni', 'boter', 'saus', 'roomspinazie', 'pesto', 'chips', 'cracker', 'snack', 'pastei', 'bladerdeeg', 'gnocchi', 'gezond'], ['kg']],
        'apples' => [['appels', 'appel', 'elstar', 'jonagold', 'goudrenet', 'granny smith', 'pink lady', 'kanzi', 'gala', 'braeburn'], ['sap', 'moes', 'taart', 'gebak', 'flap', 'stroop', 'chips', 'gedroogd', 'azijn', 'cider', 'thee', 'drink', 'smoothie', 'limonade', 'knijp', 'compote', 'pastei', 'beignet', 'crumble', 'yoghurt', 'kwark', 'vla', 'rozijn', 'kaneel', 'wafel', 'koek', 'reep', 'repen', 'cake', 'muffin', 'baby', 'hapje', 'peer', 'met ', 'ijs', 'siroop', 'strudel', 'krokant', 'granola', 'muesli', 'mango', 'banaan', 'aardbei', 'framboos', 'spread', 'jam', 'gelei', 'punch', 'perzik', 'kiwi', ' bes', 'appelflap', 'mini', 'gesneden', 'schijfjes', 'partjes', 'snack', 'boter', 'pindakaas', 'bramen', 'rabarber', 'pannenkoek', 'zuurtjes', 'snoep', 'gala diner', 'appelsap'], ['kg', 'piece']],
        'bananas' => [['bananen', 'banaan', 'banaantjes'], ['chips', 'gedroogd', 'gesuikerd', 'smoothie', 'yoghurt', 'baby', 'olvarit', 'ontbijt', 'knijp', 'drink', 'shake', 'melk', 'muffin', 'brood', 'cake', 'pannenkoek', 'snoep', 'spek', 'reep', 'repen', 'ijs', 'pudding', 'vla', 'kwark', 'hapje', 'sap', 'mango', 'aardbei', 'kiwi', 'appel', 'met ', 'kokos', 'chocola', 'muesli', 'granola', 'plantain', 'bakbanaan', 'pakket', 'koek', 'puree', ' gel', 'lolly', 'mini', 'snack', ' gum', 'whey', 'pouch', 'papje', 'slurp', 'soesjes', 'split', 'zoete streken', 'perzik', 'maize', 'to go', 'wake up', 'gurt'], ['kg', 'piece']],
        'lemons' => [['citroenen', 'citroen'], ['sap', 'limonade', 'thee', 'siroop', 'drink', 'water', 'gras', 'schil', 'olie', 'zeep', 'aroma', 'cake', 'taart', 'snoep', 'dressing', 'peper', 'mayo', 'saus', 'marinade', 'smaak', 'zuur', 'koek', 'wafel', 'ijs', 'tonic', 'soda', 'limoen', 'pers', 'rasp', 'spray', 'afwas', 'reinig', 'ontkalk', 'geur', 'kaars', 'cola', 'bier', 'radler', 'munt', 'gember', 'honing', 'sorbet', 'yoghurt', 'kwark', ' vis', 'tonijn', 'zalm', 'kip', 'pasta', 'tijm', 'met ', 'melisse', 'verbena', 'curd', 'cake', 'pastille', 'lolly', 'gom', 'balsem', 'kruiden', 'vla', 'kaas', 'muffin', 'snack', 'hummus', 'shampoo', 'douche', 'body', 'creme', ' wc ', 'tabs', 'vaatwas', 'wasmiddel', 'allesreiniger', 'spons', 'doek', 'vitamine', 'tablet', 'clear', 'fruitdrank', 'druif', 'schoonmaak', 'schuur', 'toilet', 'verfrisser', 'gekonfijt', 'rivella', 'tea'], ['piece', 'kg']],
        'beans' => [['bruine bonen', 'witte bonen', 'kidneybonen', 'zwarte bonen', 'borlottibonen', 'cannellinibonen'], ['in tomatensaus', 'tomatensaus', 'salade', 'soep', 'chili', 'mexican', 'mix', 'spek', 'maaltijd', 'met ', 'schotel', 'pasta', 'burrito', 'taco', 'tortilla', 'snack', 'chips', 'burger', 'hummus', 'spread', 'saus', 'curry'], ['kg']],
        'lentils' => [['linzen'], ['soep', 'salade', 'pasta', 'chips', 'cracker', 'burger', 'dahl', ' dal ', 'curry', 'maaltijd', 'met ', 'spread', 'hummus', 'bloem', 'meel', 'snack', 'pof', 'wafel'], ['kg']],
        'chickpeas' => [['kikkererwten'], ['hummus', 'houmous', 'chips', 'pasta', 'salade', 'soep', 'curry', 'burger', 'falafel', 'snack', 'cracker', 'bloem', 'meel', 'geroosterd', 'spread', 'met ', 'gekruid', 'pof'], ['kg']],
        'tuna' => [['tonijn', 'tonijnstukken', 'tonijnmoot', 'tonijnfilet'], ['salade', 'spread', 'pasta', 'saus', 'pizza', 'sandwich', 'wrap', 'kat', 'hond', 'met ', 'sushi', 'tartaar', 'carpaccio', 'steak', 'vers', 'salsa', 'mayo', 'pate', 'dip', 'snack', 'crackers', 'brood', 'maaltijd', 'diepvries', 'gerookt', 'poke', 'ijs', 'voer'], ['kg']],
        'sardines' => [['sardines', 'sardientjes'], ['pasta', 'spread', 'pate', 'salade', 'kat', 'hond', 'met ', 'voer'], ['kg']],
        'hake' => [['heek', 'heekfilet', 'heekfilets'], ['gepaneerd', 'paneer', 'krokant', 'vissticks', 'lekkerbek', 'kibbeling', 'saus', 'maaltijd', 'met ', 'visstick', 'burger', 'kruiden', 'gemarineerd', 'gerookt', 'ovenschotel', 'ovenvis', 'papillot', 'citroen', 'tomaat', 'pesto', 'marinade', 'bbq'], ['kg']],
        'canned tomatoes' => [['tomatenblokjes', 'gepelde tomaten', 'tomaten blokjes', 'tomatenstukjes', 'gehakte tomaten', 'tomaten gepeld', 'gesneden tomaten', 'tomaten in stukjes'], ['saus', 'soep', 'pizza', 'met ', 'pasta'], ['kg']],
        'frozen vegetables' => [['diepvries groenten', 'diepvriesgroenten', 'roerbakgroenten', 'wokgroenten', 'groentemix', 'groente mix', 'soepgroente', 'erwten en worteltjes', 'doperwten en worteltjes', 'doperwten', 'sperziebonen'], ['vers', 'saus', 'maaltijd', 'met kip', 'met gehakt', 'kip', 'gehakt', 'noedel', 'rijst', 'pasta', ' mie ', 'blik', 'glas', ' pot', 'soep ', 'salade', 'chips', 'spread', 'snack'], ['kg']],
        'walnuts' => [['walnoten', 'walnootkernen', 'walnoot'], ['brood', 'koek', 'cake', 'chocola', 'honing', 'mix', 'olie', 'taart', 'pesto', 'gebrand', 'gezouten', 'salade', 'kaas', 'yoghurt', 'muesli', 'granola', 'spread', 'reep', 'repen', 'rozijn', 'vijg', 'dadel', 'cracker', 'ijs', 'snack', 'pecan', 'cashew', 'amandel', 'hazelnoot', 'noten mix', 'studenten', 'salami'], ['kg']],
        'honey' => [['honing'], ['koek', 'thee', 'mosterd', 'yoghurt', 'dressing', 'marinade', 'saus', 'zeep', 'shampoo', 'creme', 'lotion', 'balsem', 'melk', 'cake', 'granola', 'muesli', 'noten', 'pinda', 'kip', ' ham', 'drop', 'snoep', 'lolly', 'spray', 'keel', 'hoest', 'sesam', 'wafel', 'cracker', 'kwark', 'vla', 'ijs', 'reep', 'repen', 'cruesli', 'gebak', 'limoen', 'citroen', 'gember', 'kaneel', 'worst', 'kaas', 'body', 'douche', 'haar', 'lip', 'ontbijt', 'cereal', 'loops', 'pops', 'smacks', 'bier', 'likeur', 'wodka', 'whisky', 'mede', 'drank', 'tijm', 'zalm', 'spek', 'ribs', 'spare', 'vlees', 'pastille', 'balsamico', 'vijg', 'peer', 'appel', 'walnoot', 'amandel', 'rozemarijn', 'chili', 'flu', 'menthol'], ['kg']],
        'sugar' => [['kristalsuiker', 'suiker', 'witte suiker', 'tafelsuiker'], ['poeder', 'vanille', 'riet', 'kandij', 'klont', 'stick', 'zakje', 'gelei', 'parel', 'bruin', 'donker', 'licht', 'basterd', 'zonder', 'vrij', 'wafel', 'spek', 'bonbon', 'snoep', 'koek', 'cake', 'brood', 'frisdrank', 'cola', 'zoetstof', 'stevia', 'kokos', 'druiven', 'kaneel', 'spin', 'spray', 'scrub', 'glazuur', 'decor', 'strooi', 'hagel', 'muis', 'sport', 'minder', 'weinig', 'reductie', 'vervang', 'fijn', 'glucose', 'fructose', 'dextrose', 'invert', 'stroop', 'siroop', 'palm', 'agave', 'xylitol', 'erythritol', 'kristal 5', 'biet', 'slagroom', 'nootmuskaat', 'lollies', ' gum', 'kauwgom', 'water', 'thee', 'koffie', 'drink', 'limonade', 'zoet', 'mint', '%', 'toegevoegd', 'geen', 'appelmoes', 'gesneden'], ['kg']],
        'coffee' => [['koffie', 'koffiebonen', 'snelfilter', 'filterkoffie', 'snelfilterkoffie', 'gemalen koffie'], ['pads', 'cups', 'capsule', 'instant', 'oploskoffie', 'melk', 'creamer', 'ijs', ' ice', 'latte', 'cappuccino', 'koek', 'bonbon', 'chocola', 'likeur', 'filters', 'filterzak', 'apparaat', 'machine', 'beker', 'mok', 'ontkalk', 'reinig', 'cafeinevrij', 'decaf', 'dolce gusto', 'senseo', 'nespresso', 'tassimo', ' pad', 'vanille', 'karamel', 'hazelnoot', 'gusto', 'stick', 'mix', '3 in 1', '2 in 1', 'frappe', 'cold brew', 'drink', 'zeep', 'mokka', 'speculaas', 'toffee', 'caramel', 'kaneel', 'roomboter', ' pot', ' kan ', 'schep', 'korrel', 'scrub', 'shampoo', 'body', 'douche', 'cake', 'taart', 'snoep', 'ijs', 'pods', ' cup', 'gold', 'dessert', 'pudding', 'tiramisu', 'yoghurt', 'kwark', 'vla', 'wafel', 'bean', 'grinder', 'molen', 'dosis', 'servies', 'lepel', 'suiker', 'koekjes', 'chocolade'], ['kg']],
    ];

    /** Never food, whatever the name. */
    private const EXCLUDE = ['hond', 'kat ', 'katten', 'honden', 'dieren', 'vogel', 'knaag', 'zeep', 'shampoo', 'douche', 'deodorant',
        'parfum', 'kaars', 'wasmiddel', 'vaatwas', 'tandpasta', 'luier', 'vitamine', 'tablet', 'whey', 'nutrition', 'protein',
        'baby', 'ella', 'olvarit', 'bonbebe', 'hipp', 'nutrilon', 'kauw', 'schoonmaak', 'schuur', 'toilet', 'reinig', 'verfrisser'];

    public function enabled(): bool
    {
        return (bool) config('fitness.prices.checkjebon', true);
    }

    protected function fetch(): array
    {
        $body = Http::connectTimeout(15)->timeout(60)->get(self::DATA_URL)->throw()->body();
        $date = $this->dataDate();
        $index = $this->index();
        $found = [];
        foreach ($this->products($body) as [$chain, $product]) {
            if (! is_array($product) || ! is_string($product['n'] ?? null) || ! is_numeric($product['p'] ?? null) || $product['p'] <= 0) {
                continue;
            }
            $name = $this->normalize($product['n']);
            if (($staple = $this->match($name, $index)) && ($size = $this->size($name, (string) ($product['s'] ?? '')))
                && in_array($size[1], self::STAPLES[$staple][2], true)) {
                [$amount, $unit] = $size;
                $found[$staple][] = ['price' => round($product['p'] / $amount, 2), 'unit' => $unit,
                    'product' => trim($product['n']), 'chain' => $chain, 'ean' => null, 'date' => $date];
            }
        }

        return $found;
    }

    /**
     * Every product as [chain, product], one at a time. The file is a list of
     * chains each with a list of flat product objects; reading it chain by
     * chain keeps a whole decoded copy out of memory. A file that no longer
     * looks like that is decoded whole.
     */
    private function products(string $body): \Generator
    {
        preg_match_all('/\{"n":"([^"\\\\]+)","d":\[/', $body, $heads, PREG_OFFSET_CAPTURE);
        if ($heads[0] === []) {
            foreach (json_decode($body, true) ?: [] as $chain) {
                foreach (is_array($chain['d'] ?? null) ? $chain['d'] : [] as $product) {
                    yield [(string) ($chain['n'] ?? ''), $product];
                }
            }

            return;
        }
        foreach ($heads[0] as $i => [$head, $offset]) {
            $start = $offset + strlen($head);
            $end = $heads[0][$i + 1][1] ?? strlen($body);
            preg_match_all('/\{[^{}]*\}/', substr($body, $start, $end - $start), $objects);
            foreach ($objects[0] as $object) {
                yield [$heads[1][$i][0], json_decode($object, true)];
            }
        }
    }

    /** The day the data file last changed, or today when GitHub does not say. */
    private function dataDate(): string
    {
        try {
            $date = Http::timeout(10)->get(self::COMMITS_URL, ['path' => 'data/supermarkets.json', 'per_page' => 1])
                ->json('0.commit.committer.date');

            return is_string($date) ? substr($date, 0, 10) : now()->toDateString();
        } catch (Throwable) {
            return now()->toDateString();
        }
    }

    /**
     * [amount, 'kg'|'l'|'piece'] a price is for, from the listed size ("Per
     * 500 gram", "6 x 1 L", "10 stuks", "per stuk") or else the name. A
     * product with no readable size ("Tros", "per pakket") is left out.
     */
    private function size(string $name, string $size): ?array
    {
        foreach ([$this->normalize($size), $name] as $text) {
            if ($parsed = $this->packSize($text)) {
                return $parsed;
            }
            if ($n = $this->count($text)) {
                return [$n, 'piece'];
            }
            if (preg_match('/^(per )?(1 )?(stuk|st|stuks)$/', $text)) {
                return [1, 'piece'];
            }
        }

        return null;
    }

    /** The staple a product is: a term among its words, no exclusion in its name. */
    private function match(string $name, array $index): ?string
    {
        $padded = ' '.preg_replace('/[^a-z0-9]+/', ' ', $name).' ';
        foreach (array_unique(explode(' ', trim($padded))) as $word) {
            foreach ($index[$word] ?? [] as [$staple, $term, $exclusions]) {
                if (str_contains($padded, ' '.$term.' ') && ! $this->excluded(' '.$name.' ', $exclusions)) {
                    return $staple;
                }
            }
        }

        return null;
    }

    /** Terms by their first word, so each product is checked against a few. */
    private function index(): array
    {
        $index = [];
        foreach (self::STAPLES as $staple => [$terms, $exclusions]) {
            foreach ($terms as $term) {
                $index[strtok($term, ' ')][] = [$staple, $term, [...self::EXCLUDE, ...$exclusions]];
            }
        }

        return $index;
    }

    protected function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }
}
