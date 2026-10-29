<?php

namespace App\Console\Commands;

use App\Models\EcuFile;
use App\Models\MapDefinition;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('binbench:claim-files {email : Account that should own the files}')]
#[Description('Give files and definitions uploaded before login existed to an account')]
class ClaimFiles extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No account with that email. Register it on the login page first.');

            return self::FAILURE;
        }

        $files = EcuFile::whereNull('user_id')->update(['user_id' => $user->id]);
        $definitions = MapDefinition::whereNull('user_id')->update(['user_id' => $user->id]);
        $this->info("{$user->email} now owns {$files} file(s) and {$definitions} definition(s) that had no owner.");

        return self::SUCCESS;
    }
}
