<?php

declare(strict_types=1);

// LOCAL PostgreSQL only. Reuses stored vectors, never calls the AI layer.
// Run from the main application's root to use its existing local DB config.
define('ASKMYDOCS_PERFORMANCE_LIBRARY_ONLY', true);
require __DIR__.'/cloud-snapshot.php';
$pdo = null;
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = Illuminate\Support\Facades\DB::connection();
    if (! $app->environment('local') || $connection->getDriverName() !== 'pgsql'
        || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('This benchmark requires a local loopback PostgreSQL connection.');
    }
    $pdo = $connection->getPdo();
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->exec("SET LOCAL statement_timeout='1500ms'");
    $pdo->exec("SET LOCAL lock_timeout='250ms'");
    $pdo->exec("SET LOCAL hnsw.iterative_scan='strict_order'");
    // Only this tiny local corpus may be grouped. Never use this as a Cloud collector.
    $count = (int) $pdo->query('SELECT count(*) FROM knowledge_chunks')->fetchColumn();
    if ($count > 10000) {
        throw new RuntimeException('Local corpus exceeds this harness budget.');
    }
    $scope = $pdo->query("SELECT k.tenant_id,k.project_key,count(*) population FROM knowledge_chunks k WHERE k.embedding IS NOT NULL GROUP BY k.tenant_id,k.project_key ORDER BY count(*) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (! $scope) {
        throw new RuntimeException('No stored vector.');
    }
    $where = 'k.tenant_id=? AND k.project_key IS NOT DISTINCT FROM ? AND EXISTS(SELECT 1 FROM knowledge_documents d WHERE d.id=k.knowledge_document_id AND d.tenant_id=? AND d.status<>? AND d.deleted_at IS NULL)';
    $bindings = [$scope['tenant_id'], $scope['project_key'], $scope['tenant_id'], 'archived'];
    $seed = $pdo->prepare('SELECT k.embedding::text FROM knowledge_chunks k WHERE '.$where.' AND k.embedding IS NOT NULL ORDER BY k.id LIMIT 1');
    $seed->execute($bindings);
    $vector = $seed->fetchColumn();
    if (! is_string($vector)) {
        throw new RuntimeException('No active stored vector.');
    }
    $wide = 'SELECT k.*, (1-(k.embedding <=> ?::vector)) vector_score FROM knowledge_chunks k WHERE '.$where;
    $narrow = 'SELECT k.id,k.knowledge_document_id,k.project_key,k.heading_path,k.chunk_text,k.metadata,k.chunk_hash,k.chunk_order,(1-(k.embedding <=> ?::vector)) vector_score FROM knowledge_chunks k WHERE '.$where;
    $shapes = [
        'legacy_exact' => [$wide.' AND (1-(k.embedding <=> ?::vector))>=? ORDER BY vector_score DESC LIMIT 24', [$vector, ...$bindings, $vector, 0.3]],
        'known_fix' => [$wide.' ORDER BY k.embedding <=> ?::vector ASC LIMIT 24', [$vector, ...$bindings, $vector]],
        'projection_experiment' => [$narrow.' ORDER BY k.embedding <=> ?::vector ASC LIMIT 24', [$vector, ...$bindings, $vector]],
    ];
    $execute = static function (array $shape) use ($pdo): array {
        $start = hrtime(true);
        $statement = $pdo->prepare($shape[0]);
        $statement->execute($shape[1]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $elapsed = (hrtime(true)-$start)/1e6;
        $accepted = array_values(array_filter($rows, static fn ($r) => $r['vector_score'] !== null && (float) $r['vector_score'] >= 0.3));
        $retrievalFields = ['id','knowledge_document_id','project_key','heading_path','chunk_text','metadata','chunk_hash','chunk_order','vector_score'];
        $payload = array_map(static fn ($r) => array_intersect_key($r, array_flip($retrievalFields)), $accepted);
        return ['ms' => round($elapsed, 3), 'wire_value_bytes' => array_sum(array_map(static fn ($r) => array_sum(array_map(static fn ($v) => strlen((string) $v), $r)), $rows)),
            'ids' => array_column($accepted, 'id'), 'scores' => array_column($accepted, 'vector_score'), 'payload' => $payload];
    };
    $leafStart = isset($argv[1]) && $argv[1] === '--leaf' ? (int) ($argv[2] ?? 0) : 0;
    if ($leafStart > 0) {
        $shapeName = $argv[3] ?? 'projection_experiment';
        if (! isset($shapes[$shapeName])) {
            throw new InvalidArgumentException('Unknown shape');
        }
        while (hrtime(true) < $leafStart) {
            usleep(1000);
        }
        $start = hrtime(true);
        $samples = [];
        for ($i = 0; $i < 3; $i++) {
            $samples[] = $execute($shapes[$shapeName])['ms'];
        }
        $result = ['sql_ms' => $samples, 'start_ns' => $start, 'end_ns' => hrtime(true),
            'start_lateness_ms' => round(($start-$leafStart)/1e6, 3)];
    } else {
        // Warm each form equally; first executions are recorded separately,
        // never called cold-cache measurements. ABCCBA alternates ordering.
        $result = ['mode' => 'local_only_read_only_stored_vector', 'total_chunks' => $count,
            'sample_scope_chunks' => (int) $scope['population'], 'k' => 24, 'floor' => 0.3,
            'limitations' => 'One largest local scope, first active stored vector; no actor ACL replay; not Cloud or a cold-cache benchmark.'];
        foreach ($shapes as $name => $shape) {
            $row = $execute($shape);
            $result['first_ms'][$name] = $row['ms'];
        }
        $samples = $ids = $scores = $payloads = [];
        for ($round = 0; $round < 10; $round++) {
            $order = $round % 2 === 0 ? array_keys($shapes) : array_reverse(array_keys($shapes));
            foreach ($order as $name) {
                $row = $execute($shapes[$name]);
                $samples[$name][] = $row['ms'];
                $result['value_bytes'][$name] = $row['wire_value_bytes'];
                $ids[$name] = $row['ids'];
                $scores[$name] = $row['scores'];
                $payloads[$name] = $row['payload'];
            }
        }
        foreach ($samples as $name => $times) {
            sort($times);
            $result['warm'][$name] = ['samples' => count($times), 'p50_ms' => $times[4], 'p95_ms' => $times[9],
                'accepted' => count($ids[$name]), 'recall_at_24_vs_legacy' => count($ids['legacy_exact']) === 0 ? null
                    : count(array_intersect($ids['legacy_exact'], $ids[$name])) / count($ids['legacy_exact']),
                'same_order_and_scores' => $ids[$name] === $ids['legacy_exact'] && $scores[$name] === $scores['legacy_exact'],
                'same_retrieval_fields' => $payloads[$name] == $payloads['legacy_exact']];
            $statement = $pdo->prepare('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) '.$shapes[$name][0]);
            $statement->execute($shapes[$name][1]);
            $plan = json_decode($statement->fetchColumn(), true)[0];
            $result['plans'][$name] = askmydocsPerformancePlan($plan['Plan']);
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['error_type' => get_class($exception), 'sqlstate' => (string) $exception->getCode()]), PHP_EOL;
    exit(1);
} finally {
    if ($pdo?->inTransaction()) {
        $pdo->rollBack();
    }
}
