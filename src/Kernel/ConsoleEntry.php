<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use AtarCms\Cli\Console;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Doctor\Doctor;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\NativePlatform;
use AtarCms\Doctor\Platform;
use Throwable;

/**
 * What bin/atar runs. `doctor` works without booting the site (booting is one of its checks), so it can explain a
 * missing .env or an invalid APP_URL; every other command boots first and a boot failure is one plain message
 * pointing at the doctor, not a stack trace. `doctor --help` is help like any other command's, so it boots.
 */
final class ConsoleEntry
{
    /**
     * @param list<string> $args the arguments after the script name
     * @param bool $errorHandler register the application's PHP error handler (tests leave PHPUnit's in place)
     */
    public static function run(Paths $paths, array $args, Output $output, ?Platform $platform = null, bool $errorHandler = true): int
    {
        if (($args[0] ?? null) === 'doctor' && !Console::asksForHelp(array_slice($args, 1))) {
            $context = new DoctorContext($paths, $platform ?? new NativePlatform(), DoctorOptions::fromInput(Input::parse(array_slice($args, 1))));

            return Doctor::standard()->report($context, $output);
        }
        try {
            $app = Application::boot($paths);
        } catch (Throwable $e) {
            $output->error('The site does not boot: ' . $e->getMessage());
            $output->error('Run "php bin/atar doctor" for a full check of the installation.');

            return 1;
        }
        if ($errorHandler) {
            $app->registerErrorHandler();
        }

        return $app->console()->run($args, $output);
    }
}
