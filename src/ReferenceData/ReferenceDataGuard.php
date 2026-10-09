<?php

/**
 * Loads the reference data for a UDS page, or stops the page.
 *
 * A broken reference file must not produce figures, so every page loads the
 * data before doing anything else. On failure the problem is logged, and the
 * page shows a generic message; administrators, who are the ones able to fix
 * the file, also see which file and entry is wrong and why.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ReferenceData;

use Psr\Log\LoggerInterface;

final readonly class ReferenceDataGuard
{
    public function __construct(
        private ReferenceDataLoader $loader,
        private LoggerInterface $logger,
    ) {
    }

    public function loadOrStop(bool $showDetails): ReferenceData
    {
        try {
            return $this->loader->load();
        } catch (ReferenceDataException $e) {
            $this->logger->error('UDS Report reference data is invalid', ['location' => $e->location, 'reason' => $e->reason]);
            $problem = $e;
        }
        $this->stop($problem, $showDetails);
    }

    private function stop(ReferenceDataException $problem, bool $showDetails): never
    {
        http_response_code(500);
        echo '<p>' . xlt('The UDS Report cannot run because one of its reference data files is invalid. An administrator can correct the file, or reinstall the module.') . '</p>';
        if ($showDetails) {
            echo '<p><code>' . text($problem->location) . '</code>: ' . text($problem->reason) . '</p>';
        }
        exit;
    }
}
