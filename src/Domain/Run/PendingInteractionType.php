<?php

declare(strict_types=1);

namespace Rick\Laravel\Domain\Run;

enum PendingInteractionType: string
{
    case None = 'none';
    case CandidateReview = 'candidate_review';
    case ExternalInput = 'external_input';
}
