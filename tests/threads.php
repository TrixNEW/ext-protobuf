<?php

// Stress test for ext-protobuf on pmmpthread, run with the google/protobuf composer autoloader:
// php tests/threads.php /path/to/vendor/autoload.php

declare(strict_types=1);

use Google\Protobuf\ListValue;
use Google\Protobuf\StringValue;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;

$autoload = $argv[1] ?? throw new RuntimeException('usage: pbthreads.php /path/to/vendor/autoload.php');
require $autoload;

function sample(int $seed) : Struct{
    $s = new Struct();
    $fields = $s->getFields();
    $fields['name'] = (new Value())->setStringValue("player-$seed-" . str_repeat('x', $seed % 300));
    $fields['level'] = (new Value())->setNumberValue($seed);
    $fields['online'] = (new Value())->setBoolValue($seed % 2 === 0);
    $list = new ListValue();
    for($i = 0; $i < $seed % 7; ++$i){
        $list->getValues()[] = (new Value())->setStringValue("item$i");
    }
    $fields['items'] = (new Value())->setListValue($list);
    return $s;
}

/** @return string error, '' when fine */
function work(int $seed, int $rounds) : string{
    for($i = 0; $i < $rounds; ++$i){
        $n = $seed * 7919 + $i;
        $bytes = sample($n)->serializeToString();
        $back = new Struct();
        $back->mergeFromString($bytes);
        if(count($back->getFields()) !== 4 || $back->getFields()['items']->getListValue()->getValues()->count() !== $n % 7 || $back->getFields()['online']->getBoolValue() !== ($n % 2 === 0) || $back->getFields()['level']->getNumberValue() !== (float) $n){
            return "round trip mismatch at $n";
        }
        $json = $back->serializeToJsonString();
        $fromJson = new Struct();
        $fromJson->mergeFromJsonString($json);
        if($fromJson->getFields()['name']->getStringValue() !== $back->getFields()['name']->getStringValue()){
            return "json mismatch at $n";
        }
        $big = new StringValue(['value' => str_repeat(chr(97 + $n % 26), 16_384)]);
        $sv = new StringValue();
        $sv->mergeFromString($big->serializeToString());
        if($sv->getValue() !== $big->getValue()){
            return "big string mismatch at $n";
        }
        try{
            (new Struct())->mergeFromString(random_bytes(1 + $n % 64));
        }catch(Exception){
        }
    }
    return '';
}

final class Worker extends Thread{
    public function __construct(private ThreadSafeArray $out, private int $seed, private int $rounds, private ?string $autoload){}

    public function run() : void{
        if($this->autoload !== null){
            require $this->autoload;
        }
        $this->out[$this->seed] = work($this->seed, $this->rounds);
    }
}

$check = static function(bool $ok, string $what) : void{
    if(!$ok){
        fwrite(STDERR, "FAIL $what\n");
        exit(1);
    }
};

$check(extension_loaded('protobuf'), 'protobuf extension not loaded');
$check((new ReflectionClass(Google\Protobuf\Internal\Message::class))->isInternal(), 'Message is not the extension class');

$t = hrtime(true);
$err = work(1, 2_000);
$check($err === '', "main thread: $err");
printf("main thread: 2000 rounds in %.0f ms\n", (hrtime(true) - $t) / 1e6);

// INHERIT_NONE with its own autoloader and INHERIT_ALL copying the main thread's classes, started while main keeps working
foreach([[Thread::INHERIT_NONE, $autoload, 'inherit none'], [Thread::INHERIT_ALL, null, 'inherit all']] as [$options, $loader, $name]){
    $out = new ThreadSafeArray();
    $threads = [];
    $t = hrtime(true);
    for($i = 0; $i < 16; ++$i){
        $threads[] = $w = new Worker($out, 100 + $i, 1_000, $loader);
        $w->start($options);
        $check(work(500 + $i, 50) === '', "main work while starting threads ($name)");
    }
    foreach($threads as $w){
        $w->join();
    }
    $errors = array_filter(iterator_to_array($out));
    $check(count($out) === 16 && $errors === [], "$name: " . count($out) . ' results ' . json_encode($errors));
    printf("%s: 16 threads x 1000 rounds in %.0f ms\n", $name, (hrtime(true) - $t) / 1e6);
}

// create/join cycles must not grow memory
$rss = static fn() : int => preg_match('/VmRSS:\s+(\d+)/', (string) file_get_contents('/proc/self/status'), $m) === 1 ? (int) $m[1] : 0;
$cycle = static function(int $n) use ($autoload) : void{
    for($i = 0; $i < $n; ++$i){
        $out = new ThreadSafeArray();
        $ws = [];
        for($j = 0; $j < 4; ++$j){
            $ws[] = $w = new Worker($out, $j, 20, $j % 2 === 0 ? $autoload : null);
            $w->start($j % 2 === 0 ? Thread::INHERIT_NONE : Thread::INHERIT_ALL);
        }
        foreach($ws as $w){
            $w->join();
        }
    }
};
$cycle(50);
$before = $rss();
$cycle(200);
$after = $rss();
printf("create/join: rss %d KB -> %d KB after 800 more threads\n", $before, $after);
$check($after - $before < 20_000, 'memory grew by ' . ($after - $before) . ' KB over 200 cycles');
echo "OK\n";
