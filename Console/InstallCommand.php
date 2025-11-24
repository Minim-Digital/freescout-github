<?php

namespace Modules\Github\Console;

use App\Misc\Helper;
use Illuminate\Console\Command;
use Nwidart\Modules\Facades\Module;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout-github:install {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the database migrations for the FreeScout GitHub module.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $moduleName = 'Github';

        $module = Module::find($moduleName);

        if (!$module) {
            $this->error('The GitHub module is not registered.');

            return 1;
        }

        $this->info('Running migrations for the GitHub module...');

        $parameters = ['module' => $moduleName];

        $force = $this->option('force');

        if ($force) {
            $parameters['--force'] = true;
        }

        try {
            $exitCode = $this->call('module:migrate', $parameters);
        } catch (\Throwable $exception) {
            $this->error('Running GitHub module migrations failed: '.$exception->getMessage());
            Helper::logException($exception, '[GitHub] install command');

            return 1;
        }

        if ($exitCode === 0) {
            $this->info('GitHub module migrations completed successfully.');
        } else {
            $this->error('GitHub module migrations finished with errors.');
        }

        return $exitCode;
    }
}

