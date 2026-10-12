<?php

namespace App\Enums;

/** The contest rubric categories a piece of feedback can be about. */
enum FeedbackCategory: string
{
    case Functionality = 'functionality';
    case ProblemRelevance = 'problem_relevance';
    case AiIntegration = 'ai_integration';
    case CodeQuality = 'code_quality';
    case UxDesign = 'ux_design';
    case BusinessImpact = 'business_impact';

    public function label(): string
    {
        return match ($this) {
            self::Functionality => 'Functionality',
            self::ProblemRelevance => 'Problem relevance',
            self::AiIntegration => 'AI integration',
            self::CodeQuality => 'Code quality',
            self::UxDesign => 'UX & design',
            self::BusinessImpact => 'Business impact',
        };
    }
}
