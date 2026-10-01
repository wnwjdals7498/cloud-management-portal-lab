<?php
declare(strict_types=1);

namespace App\Commands;

use App\Support\SimulatorServices;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class ResendMissingCallback extends BaseCommand
{
    protected $group = 'Simulator';

    protected $name = 'simulator:resend-missing';

    protected $description = 'Schedule the original missing simulator completion for reconciliation.';

    protected $usage = 'simulator:resend-missing <external_job_id>';

    protected $arguments = ['external_job_id' => 'The simulator job whose callback was intentionally omitted.'];

    public function run(array $params)
    {
        $runtime = Runtime::config();
        $environment = defined('ENVIRONMENT') ? ENVIRONMENT : '';
        if (! SimulatorCliPolicy::allows(PHP_SAPI, $environment, $runtime['mode'] ?? null)) {
            CLI::error('Missing callback recovery is available only in the simulated development CLI.');
            return EXIT_ERROR;
        }

        $externalJobId = $params[0] ?? null;
        if (! is_string($externalJobId) || $externalJobId === '') {
            CLI::error('A simulator job reference is required.');
            return EXIT_ERROR;
        }

        try {
            $result = SimulatorServices::simulator()->scheduleMissingCallbackResend($externalJobId);
            CLI::write(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return EXIT_SUCCESS;
        } catch (PortalException $exception) {
            CLI::error($exception->errorCode . ': ' . $exception->getMessage());
            return EXIT_ERROR;
        } catch (Throwable) {
            CLI::error('The callback recovery reservation failed; diagnostics were suppressed.');
            return EXIT_ERROR;
        }
    }
}
