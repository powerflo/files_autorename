<?php

namespace OCA\Files_AutoRename\Service;

enum RuleAnnotation: string {
    case ConflictCancel = 'ConflictCancel';
    case ConflictKeepBoth = 'ConflictKeepBoth';
    case ConflictKeepBothIfDifferent = 'ConflictKeepBothIfDifferent';
}