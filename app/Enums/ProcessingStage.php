<?php

namespace App\Enums;

/**
 * One row is written to media_processing_logs per stage attempt, so this list
 * doubles as the audit trail vocabulary for "what happened to this asset".
 */
enum ProcessingStage: string
{
    case Extraction = 'extraction';
    case Validation = 'validation';
    case Deduplication = 'deduplication';
    case Download = 'download';
    case MetadataExtraction = 'metadata_extraction';
    case RelevanceAnalysis = 'relevance_analysis';
    case QualityAnalysis = 'quality_analysis';
    case VariantGeneration = 'variant_generation';
    case Selection = 'selection';
    case Publishing = 'publishing';
}
