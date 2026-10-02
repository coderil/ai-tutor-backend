<?php

namespace App\Lessons;

use RuntimeException;

/**
 * The recall grader's reply could not be used. Nothing is stored and the learner can
 * submit again.
 */
class GradingFailed extends RuntimeException {}
