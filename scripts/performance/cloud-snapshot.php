<?php

declare(strict_types=1);

/* Run from the application root. Portable to an older deployed release.
 * No AI, HTTP, vector-search execution, cache writes or schema changes.
 * All SQL runs in a rolled-back READ ONLY transaction; output is allowlisted.
 */

function askmydocsPerformancePlan(array $node): array
{
    $safe = array_intersect_key($node, array_flip([
        'Node Type', 'Relation Name', 'Index Name', 'Join Type', 'Plan Rows',
        'Plan Width', 'Startup Cost', 'Total Cost', 'Actual Rows', 'Actual Loops',
        'Actual Total Time', 'Shared Hit Blocks', 'Shared Read Blocks', 'Temp Read Blocks', 'Temp Written Blocks',
        'Rows Removed by Filter', 'Rows Removed by Index Recheck', 'Sort Space Used', 'Sort Space Type',
    ]));
    $safe['Plans'] = array_map('askmydocsPerformancePlan', $node['Plans'] ?? []);
    return $safe;
}

function askmydocsPerformanceRead(PDO $pdo, string $sql, array $bindings = []): array
{
    $pdo->exec('SAVEPOINT performance_snapshot');
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        $pdo->exec('ROLLBACK TO SAVEPOINT performance_snapshot');
        // Never print exception messages: they may contain SQL or bindings.
        $rows = ['unavailable_sqlstate' => (string) $exception->getCode()];
    }
    $pdo->exec('RELEASE SAVEPOINT performance_snapshot');
    return $rows;
}

function askmydocsPerformanceSnapshot(PDO $pdo, int $runId): array
{
    $read = static fn (string $sql, array $bindings = []): array => askmydocsPerformanceRead($pdo, $sql, $bindings);
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->exec("SET LOCAL statement_timeout = '1500ms'");
        $pdo->exec("SET LOCAL lock_timeout = '250ms'");
        $pdo->exec("SET LOCAL idle_in_transaction_session_timeout = '5s'");
        $result = ['schema_version' => 1, 'observed_at_utc' => gmdate('c'),
            'mode' => 'read_only; catalog/statistics/one run; no ANALYZE; no vector search; no AI',
            'limits' => ['statement_ms' => 1500, 'lock_ms' => 250, 'span_groups' => 64, 'run_id' => $runId]];
        $result['server'] = $read('SELECT current_setting(\'server_version\') version, pg_postmaster_start_time() server_started_at, pg_database_size(current_database()) database_bytes');
        $result['extensions'] = $read("SELECT extname, extversion FROM pg_extension WHERE extname IN ('vector','pg_trgm','pg_stat_statements') ORDER BY extname");
        $result['settings'] = $read("SELECT name, setting, unit FROM pg_settings WHERE name IN ('max_connections','shared_buffers','work_mem','track_io_timing','compute_query_id','hnsw.ef_search','hnsw.iterative_scan','hnsw.max_scan_tuples','hnsw.scan_mem_multiplier') ORDER BY name");
        $result['statement_statistics_support'] = $read("SELECT EXISTS(SELECT 1 FROM pg_available_extensions WHERE name='pg_stat_statements') available, EXISTS(SELECT 1 FROM pg_extension WHERE extname='pg_stat_statements') installed, current_setting('shared_preload_libraries',true) LIKE '%pg_stat_statements%' preloaded, to_regclass('pg_stat_statements') IS NOT NULL view_present");
        $result['database_counters'] = $read('SELECT numbackends, xact_commit, xact_rollback, blks_read, blks_hit, temp_files, temp_bytes, deadlocks, blk_read_time, blk_write_time, stats_reset FROM pg_stat_database WHERE datname = current_database()');
        $result['tables'] = $read("SELECT relname, n_live_tup estimated_live_rows, n_dead_tup, seq_scan, idx_scan, last_analyze, last_autoanalyze, last_autovacuum, pg_relation_size(relid) heap_bytes, pg_indexes_size(relid) index_bytes, pg_total_relation_size(relid) total_bytes FROM pg_stat_user_tables WHERE relname IN ('knowledge_chunks','knowledge_documents','embedding_cache','agent_diagnostic_spans') ORDER BY relname");
        $result['indexes'] = $read("SELECT t.relname table_name, i.relname index_name, a.amname method, x.indisvalid, x.indisready, coalesce(s.idx_scan,0) scans, pg_relation_size(i.oid) bytes, s.idx_tup_read, s.idx_tup_fetch FROM pg_index x JOIN pg_class t ON t.oid=x.indrelid JOIN pg_class i ON i.oid=x.indexrelid JOIN pg_am a ON a.oid=i.relam LEFT JOIN pg_stat_user_indexes s ON s.indexrelid=i.oid WHERE t.relname IN ('knowledge_chunks','knowledge_documents','embedding_cache') ORDER BY t.relname,i.relname LIMIT 80");
        // Two snapshots, separate statements. Do not output SQL, identities,
        // application_name, client addresses, PIDs or blocking query text.
        for ($i = 0; $i < 2; $i++) {
            $read('SELECT pg_stat_clear_snapshot()');
            $result['activity'][] = ['at_utc' => gmdate('c'), 'groups' => $read("SELECT backend_type, state, wait_event_type, wait_event, count(*) connections, max(extract(epoch FROM clock_timestamp()-query_start)) max_query_age_seconds, sum(CASE WHEN cardinality(pg_blocking_pids(pid))>0 THEN 1 ELSE 0 END) blocked_connections FROM pg_stat_activity WHERE datname = current_database() AND pid<>pg_backend_pid() GROUP BY backend_type,state,wait_event_type,wait_event ORDER BY count(*) DESC LIMIT 32")];
            if ($i === 0) {
                usleep(500000);
            }
        }
        $result['io_counters'] = $read("SELECT backend_type, object, context, sum(reads) reads, sum(read_time) read_time_ms, sum(writes) writes, sum(write_time) write_time_ms, sum(hits) hits, min(stats_reset) earliest_reset FROM pg_stat_io GROUP BY backend_type,object,context ORDER BY backend_type,object,context LIMIT 48");
        // Use a primary-key lookup, never a scan for the 'latest' run.
        $result['case_run'] = $read('SELECT id, status, created_at, started_at, completed_at, last_sequence FROM agent_runs WHERE id = ?', [$runId]);
        $scope = $read('SELECT tenant_id, project_key FROM agent_runs WHERE id=?', [$runId]);
        if (isset($scope[0]['tenant_id'])) {
            $sql = 'SELECT k.id FROM knowledge_chunks k WHERE k.tenant_id=?';
            $bindings = [$scope[0]['tenant_id']];
            if (($scope[0]['project_key'] ?? '') !== '') {
                $sql .= ' AND k.project_key=?';
                $bindings[] = $scope[0]['project_key'];
            }
            $sql .= ' AND EXISTS(SELECT 1 FROM knowledge_documents d WHERE d.id=k.knowledge_document_id AND d.tenant_id=? AND d.status<>? AND d.deleted_at IS NULL)';
            $bindings = [...$bindings, $scope[0]['tenant_id'], 'archived'];
            $estimated = $read('EXPLAIN (FORMAT JSON) '.$sql, $bindings);
            $plan = json_decode($estimated[0]['QUERY PLAN'] ?? 'null', true);
            $result['case_base_population_estimate'] = isset($plan[0]['Plan'])
                ? askmydocsPerformancePlan($plan[0]['Plan'])
                : ['unavailable_sqlstate' => $estimated['unavailable_sqlstate'] ?? 'plan_unavailable'];
            $result['case_estimate_scope'] = 'tenant/project/active documents only; not actual query or full ACL/retrieval filters';
        }
        $result['case_phases'] = $read("SELECT phase, status, count(*) spans, min(started_at) first_started_at, max(ended_at) last_ended_at, min(duration_ms) min_ms, max(duration_ms) max_ms FROM agent_diagnostic_spans WHERE agent_run_id=? GROUP BY phase,status ORDER BY min(started_at) LIMIT 64", [$runId]);
        $result['case_worker'] = $read("SELECT metadata_json->'runtime'->>'queue_connection' queue_connection, metadata_json->'runtime'->>'queue_name' queue_name, metadata_json->'runtime'->>'release' release, metadata_json->'runtime'->>'job_timeout_seconds' job_timeout_seconds, metadata_json->'runtime'->>'research_concurrency' research_concurrency, metadata_json->'runtime'->>'research_timeout_seconds' research_timeout_seconds, metadata_json->>'attempt' attempt, status, started_at, ended_at FROM agent_diagnostic_spans WHERE agent_run_id=? AND phase='worker.execute' ORDER BY started_at LIMIT 4", [$runId]);
        $result['read_only'] = $read('SHOW transaction_read_only');
        return $result;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

if (! defined('ASKMYDOCS_PERFORMANCE_LIBRARY_ONLY')) {
    $pdo = null;
    $output = [];
    try {
        require getcwd().'/vendor/autoload.php';
        $application = require getcwd().'/bootstrap/app.php';
        $application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $connection = Illuminate\Support\Facades\DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required');
        }
        // The run ID is not a tenant identifier; no input/plan/result is read.
        $runId = isset($argv[1]) ? max(1, (int) $argv[1]) : 186;
        $output = askmydocsPerformanceSnapshot($connection->getPdo(), $runId);
        $output['application'] = ['php' => PHP_VERSION, 'laravel' => $application->version(),
            'config_cached' => $application->configurationIsCached(), 'code_sha256' => []];
        foreach (['app/Services/Kb/KbSearchService.php', 'app/Services/Kb/EmbeddingCacheService.php',
            'app/Jobs/ExecuteAgentRunJob.php', 'app/Services/Chat/Reasoning/ResearchFanout.php', 'composer.lock'] as $file) {
            $output['application']['code_sha256'][$file] = is_file($file) ? hash_file('sha256', $file) : null;
        }
        // Only technical configuration, never dump config/env or queue credentials.
        foreach (['queue.default', 'agent.queue', 'agent.job_timeout_seconds', 'agent.tools.fanout_concurrency',
            'agent.tools.fanout_timeout_seconds', 'reasoning.parallel_research', 'reasoning.research_concurrency',
            'reasoning.research_timeout_seconds', 'kb.ingest.queue', 'kb.embedding_cache.enabled',
            'kb.hybrid_search.enabled', 'kb.reranking.candidate_multiplier'] as $key) {
            $output['application']['config'][$key] = config($key);
        }
        $output['limitations'] = ['Command process and disk hashes do not attest loaded worker code.',
            'Cumulative counters are not rates; activity samples may miss short waits.',
            'Cloud CPU/RAM/IOPS, instance sizing, worker counts, autoscaling and pool mode require dashboard evidence.',
            'No EXPLAIN ANALYZE or replay of the stalled query was performed.'];
    } catch (Throwable $exception) {
        $output['error'] = ['type' => get_class($exception), 'sqlstate' => (string) $exception->getCode()];
    }
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
}
