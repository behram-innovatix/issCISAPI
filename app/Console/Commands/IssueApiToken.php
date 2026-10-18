<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('cis:token
    {email=cis-console@iss.local : Service account the token belongs to}
    {--name=CIS web app : Token name, shown when tokens are listed or revoked}')]
#[Description('Issue the API token the CIS web app uses to read dashboards and manage connections (set it as CIS_API_TOKEN)')]
class IssueApiToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::firstOrCreate(
            ['email' => $this->argument('email')],
            ['name' => 'CIS service account', 'password' => Str::password(40)],
        );

        $token = $user->createToken($this->option('name'), ['insights:read', 'connections:manage']);

        $this->components->info("Token issued for {$user->email}. Add it to the CIS web app's .env.local:");
        $this->line("CIS_API_TOKEN={$token->plainTextToken}");

        return self::SUCCESS;
    }
}
