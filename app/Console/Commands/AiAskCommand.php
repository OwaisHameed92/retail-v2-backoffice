<?php

namespace App\Console\Commands;

use App\Domain\Ai\Actions\RunAssistant;
use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Manual test of the AI assistant against the real API (module 6.1). Staff only: it runs on the server.
 *
 * Without --user the question is asked as a system job: read tools only, no changes can even be proposed.
 * With --user=<email> it runs as that member, with their role; write tools create proposals (not confirmed).
 */
class AiAskCommand extends Command
{
    protected $signature = 'ai:ask
        {company : Company id or exact name}
        {question : The question to ask}
        {--user= : E-mail of a member to ask as (default: a read-only system job)}';

    protected $description = 'Ask the AI assistant a question about one company (uses the real API and is metered)';

    public function handle(RunAssistant $assistant): int
    {
        $company = $this->company((string) $this->argument('company'));

        if ($company === null) {
            $this->error('No company found with that id or name.');

            return self::FAILURE;
        }

        try {
            $context = $this->askAs($company);

            $reply = $assistant->handle($context, (string) $this->argument('question'), onText: function (string $text): void {
                $this->output->write($text);
            });
        } catch (AiUnavailable|AiAccessDenied $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine(2);

        foreach ($reply->proposals as $proposal) {
            $this->warn("Proposed (not confirmed): {$proposal->preview} [{$proposal->id}]");
        }

        $this->line(sprintf(
            '<fg=gray>Conversation %s · %d step(s) · %d in / %d out / %d cache read / %d cache write tokens · £%s</>',
            $reply->conversation->id,
            $reply->steps,
            $reply->usage->inputTokens,
            $reply->usage->outputTokens,
            $reply->usage->cacheReadTokens,
            $reply->usage->cacheWriteTokens,
            $reply->costGbp,
        ));

        return self::SUCCESS;
    }

    private function company(string $idOrName): ?Company
    {
        return Company::query()->whereKey($idOrName)->first()
            ?? Company::query()->where('name', $idOrName)->first();
    }

    private function askAs(Company $company): AiContext
    {
        $email = $this->option('user');

        if (! is_string($email) || $email === '') {
            return AiContext::forSystem($company, AiFeature::Assistant);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw AiAccessDenied::notMember();
        }

        return AiContext::forUser($user, $company);
    }
}
