-- Parallel test runs (ParaTest) give every worker its own database, atar_{worker}_test, created on first use
-- (tests/Support/WorkerDatabase.php).
GRANT ALL PRIVILEGES ON `atar\_%\_test`.* TO 'atar'@'%';
