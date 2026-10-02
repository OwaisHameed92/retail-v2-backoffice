<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;

/**
 * One anomaly detector (module 6.6). Deterministic: the same rows always give the same findings. Runs inside the
 * business's company scope (DetectAnomalies), reads raw till rows or `rpt_*` tables always by `company_id`, and
 * returns nothing when the baseline is too thin to judge (minimum volumes keep normal noise quiet).
 */
interface Detector
{
    /** The runs it takes part in: DetectionWindow::HOURLY and / or DAILY. */
    public function runsIn(string $mode): bool;

    /**
     * @return list<AnomalyFinding>
     */
    public function detect(DetectionWindow $window): array;
}
