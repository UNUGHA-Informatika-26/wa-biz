<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SetWhatsAppAccessToken extends Command
{
    protected $signature = 'wabiz:whatsapp:set-access-token {connectionId? : Existing WhatsApp connection ID}';

    protected $description = 'Securely set a Meta Cloud API access token on one local WhatsApp connection.';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'wabiz') {
            $this->error('This command is restricted to APP_ENV=local and the wabiz database.');

            return self::FAILURE;
        }

        $connectionIdInput = $this->argument('connectionId');
        if ($connectionIdInput === null) {
            $connectionIdInput = $this->ask('Existing WhatsApp connection ID');
        }
        $connectionId = filter_var($connectionIdInput, FILTER_VALIDATE_INT);
        if ($connectionId === false || $connectionId < 1) {
            $this->error('Provide a valid existing connection ID.');

            return self::FAILURE;
        }

        $connection = WhatsAppConnection::query()
            ->select(['id', 'provider', 'display_name'])
            ->find($connectionId);

        if (! $connection || $connection->provider !== 'meta_cloud') {
            $this->error('A Meta Cloud API connection with that ID was not found.');

            return self::FAILURE;
        }

        $this->line("Target: connection #{$connection->id} — {$connection->display_name} (meta_cloud)");
        if (! $this->confirm('Store a new encrypted Access Token on this connection?')) {
            $this->comment('No changes made.');

            return self::FAILURE;
        }

        try {
            $token = $this->secret('Meta Cloud API Access Token (input is hidden)', false);
        } catch (Throwable) {
            $this->error('This terminal cannot securely hide input. No changes made.');

            return self::FAILURE;
        }

        if (! is_string($token) || trim($token) === '' || strlen(trim($token)) > 4096) {
            $this->error('The hidden token input was empty or invalid. No changes made.');

            return self::FAILURE;
        }

        $token = trim($token);

        try {
            DB::transaction(function () use ($connectionId, $token): void {
                $target = WhatsAppConnection::query()
                    ->lockForUpdate()
                    ->find($connectionId);

                if (! $target || $target->provider !== 'meta_cloud') {
                    throw new \RuntimeException('Target connection is no longer available.');
                }

                $credentials = $target->credentials;
                if ($credentials !== null && ! is_array($credentials)) {
                    throw new \RuntimeException('Stored credentials have an unexpected format.');
                }

                $credentials ??= [];
                $credentials['access_token'] = $token;
                $target->credentials = $credentials;
                $target->save();
            });
        } catch (Throwable) {
            $this->error('Token could not be stored. No token value was displayed or logged.');

            return self::FAILURE;
        } finally {
            if (isset($token) && is_string($token) && function_exists('sodium_memzero')) {
                sodium_memzero($token);
            }
            unset($token, $credentials, $target, $connection);
        }

        $this->info("Access Token stored encrypted for connection #{$connectionId}.");

        return self::SUCCESS;
    }
}
