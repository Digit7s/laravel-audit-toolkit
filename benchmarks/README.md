# Optional benchmarks

Run the disposable SQLite benchmark with:

```bash
AUDIT_BENCHMARK_EVENTS=1000 composer benchmark
```

Use `10000` or `100000` only when the local machine has enough temporary memory and time. The benchmark reports wall time, throughput, peak memory, serialization/redaction time, and paginated query time. It does not represent MySQL/PostgreSQL performance and must not be run against an application database.
