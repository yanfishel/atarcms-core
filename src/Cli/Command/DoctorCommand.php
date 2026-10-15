<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Doctor\Doctor;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\NativePlatform;
use AtarCms\Kernel\Paths;

/**
 * bin/atar doctor as a listed command. bin/atar itself runs the doctor before booting (ConsoleEntry), so this class
 * serves `bin/atar list` and sites that call the console of a booted application.
 */
#[AsCommand('doctor', 'Check PHP, the configuration, storage, database and web server and explain every problem: doctor [--json] [--strict] [--mail] [--http] [--http-via=<url>] [--http-ca=<pem>]')]
final class DoctorCommand implements Command
{
    public function __construct(private readonly Paths $paths) {}

    public function run(Input $input, Output $output): int
    {
        return Doctor::standard()->report(new DoctorContext($this->paths, new NativePlatform(), DoctorOptions::fromInput($input)), $output);
    }
}
