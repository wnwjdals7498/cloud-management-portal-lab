<?php
declare(strict_types=1);

namespace App\Commands;

use App\Support\SimulatorServices;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class ForceScenario extends BaseCommand
{
    protected $group = 'Simulator';

    protected $name = 'simulator:force';

    protected $description = 'Submit a local simulated VMM command with a forced development scenario.';

    protected $usage = 'simulator:force <scenario> < command.json';

    protected $arguments = ['scenario' => 'One of the validated simulator scenarios.'];

    public function run(array $params)
    {
        if (PHP_SAPI !== 'cli' || ! defined('ENVIRONMENT') || ENVIRONMENT !== 'development') {
            CLI::error('Forced simulator scenarios are available only in the development CLI.');
            return EXIT_ERROR;
        }

        $runtime = Runtime::config();
        if (($runtime['mode'] ?? null) !== 'simulated') {
            CLI::error('The configured runtime is not the local simulated mode.');
            return EXIT_ERROR;
        }

        $scenario = $params[0] ?? null;
        $rawCommand = file_get_contents('php://stdin');
        if (! is_string($scenario) || ! is_string($rawCommand) || $rawCommand === '' || strlen($rawCommand) > 65536) {
            CLI::error('A scenario name and bounded command JSON on standard input are required.');
            return EXIT_ERROR;
        }

        try {
            $command = json_decode($rawCommand, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($command) || array_is_list($command)) {
                throw new \InvalidArgumentException('A command object is required.');
            }
            $receipt = SimulatorServices::simulator()->submit($command, $scenario);
            CLI::write(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return EXIT_SUCCESS;
        } catch (PortalException $exception) {
            CLI::error($exception->errorCode . ': ' . $exception->getMessage());
            return EXIT_ERROR;
        } catch (Throwable) {
            CLI::error('The simulator command could not be processed; diagnostics were suppressed.');
            return EXIT_ERROR;
        }
    }
}
