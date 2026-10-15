<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * A group of doctor checks. A check that needs something an earlier one found broken (the site does not boot, no
 * database) reports "skip" instead of failing again.
 */
interface Check
{
    /**
     * @return list<CheckResult>
     */
    public function run(DoctorContext $context): array;
}
