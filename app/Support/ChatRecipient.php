<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Team;
use Closure;
use Illuminate\Support\Collection;

/**
 * Where a message about a team's services goes: the team's webhook, for its projects that follow the team, or the
 * webhook of one project of its own (see Project::ownsChatChannel()). A project on its own webhook is left out of every
 * message to its team, and its messages never fall back to the team.
 *
 * What a team was told and an admin's assessment stay the team's: the alerts decide for the team as a whole, then
 * send each recipient the part about its own services (see split()).
 */
final readonly class ChatRecipient
{
    public function __construct(public Team $team, public ?Project $project = null) {}

    public static function team(Team $team): self
    {
        return new self($team);
    }

    /**
     * The recipient of a project's messages: its own webhook, or its team's.
     */
    public static function of(Project $project): self
    {
        return new self($project->team, $project->ownsChatChannel() ? $project : null);
    }

    /**
     * Splits what a team's message is about by recipient: the team first, then each project on its own webhook by id.
     * Only recipients with something to send are returned.
     *
     * @template TItem
     *
     * @param  Collection<int|string, TItem>  $items
     * @param  Closure(TItem): int  $projectIdOf
     * @return Collection<string, array{recipient: self, items: Collection<int, TItem>}>
     */
    public static function split(Team $team, Collection $items, Closure $projectIdOf): Collection
    {
        $own = self::ownProjectsOf($team);
        $recipients = collect();

        foreach ($items->values() as $item) {
            $project = $own->get($projectIdOf($item));
            $recipient = new self($team, $project);
            $key = $recipient->key();

            $recipients[$key] ??= ['recipient' => $recipient, 'items' => collect()];
            $recipients[$key]['items']->push($item);
        }

        return $recipients->sortBy(fn (array $entry): int => $entry['recipient']->project?->id ?? 0);
    }

    /**
     * Every recipient of the team: the team itself, then each project on its own webhook.
     *
     * @return Collection<int, self>
     */
    public static function allOf(Team $team): Collection
    {
        return collect([self::team($team), ...self::ownProjectsOf($team)->map(fn (Project $project): self => new self($team, $project))->values()]);
    }

    /**
     * The team's projects on their own webhook, by id.
     *
     * @return Collection<int, Project>
     */
    public static function ownProjectsOf(Team $team): Collection
    {
        return Project::where('team_id', $team->id)->owningChatChannel()->orderBy('id')->get()->each(fn (Project $project) => $project->setRelation('team', $team))->keyBy('id');
    }

    public function isProject(): bool
    {
        return $this->project !== null;
    }

    public function projectId(): ?int
    {
        return $this->project?->id;
    }

    /**
     * A key no other recipient has, e.g. "team:3" or "project:12".
     */
    public function key(): string
    {
        return $this->project === null ? "team:{$this->team->id}" : "project:{$this->project->id}";
    }

    /**
     * Appended to a dedupe key, so a project's message never takes the key of its team's. Empty for the team, whose
     * keys stay those it had before projects could have their own webhook.
     */
    public function dedupeSuffix(): string
    {
        return $this->project === null ? '' : ":project:{$this->project->id}";
    }

    public function webhookUrl(): ?string
    {
        return $this->project === null ? $this->team->google_chat_webhook_url : $this->project->google_chat_webhook_url;
    }

    /**
     * The name the cards show, e.g. "Team A" or "Team A · Project X".
     */
    public function name(): string
    {
        return $this->project === null ? $this->team->name : "{$this->team->name} · {$this->project->name}";
    }

    /**
     * Whether a project's services belong to this recipient's messages.
     *
     * @param  Collection<int, Project>|null  $ownProjects  The team's projects on their own webhook (see ownProjectsOf()), to read them once.
     */
    public function includes(int $projectId, ?Collection $ownProjects = null): bool
    {
        if ($this->project !== null) {
            return $this->project->id === $projectId;
        }

        return ! ($ownProjects ?? self::ownProjectsOf($this->team))->has($projectId);
    }
}
