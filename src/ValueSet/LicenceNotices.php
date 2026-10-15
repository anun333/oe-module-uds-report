<?php

/**
 * The notices the UMLS licence asks a site to show its users when it uses
 * content from the Metathesaurus: its general statement (licence paragraph
 * 11a) and the notices Appendix 1 gives for the code systems in the Table 6A
 * value sets, word for word as NLM publishes them (Appendix 1 and 2, last
 * reviewed 2026-05-01); a line break stands where NLM breaks the CPT notice.
 * ICD-10-CM, HCPCS and RxNorm carry none.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\UdsReport\ValueSet;

final class LicenceNotices
{
    public const UMLS = 'Some material in the UMLS Metathesaurus is from copyrighted sources of the respective copyright holders. '
        . 'Users of the UMLS Metathesaurus are solely responsible for compliance with any copyright, patent or trademark restrictions '
        . 'and are referred to the copyright, patent or trademark notices appearing in the original sources, all of which are hereby '
        . 'incorporated by reference.';

    /** By the module's code system name. */
    public const BY_SYSTEM = [
        'cpt' => 'CPT™ only Copyright 2021 American Medical Association. All rights Reserved. No fee schedules, basic unit, relative '
            . 'values or related listings are included in CPT™. AMA does not directly or indirectly practice medicine or dispense medical '
            . 'services. AMA assumes no liability for data contained herein.' . "\n" . 'U.S. Government Rights' . "\n" . 'This product includes CPT™ which is '
            . 'commercial technical data and/or computer data bases and/or commercial computer software and/or commercial computer software '
            . 'documentation, as applicable which were developed exclusively at private expense by the American Medical Association, '
            . '515 North State Street, Chicago, Illinois, 60610. U.S. Government rights to use, modify, reproduce, release, perform, '
            . 'display, or disclose these technical data and/or computer data bases and/or computer software and/or computer software '
            . 'documentation are subject to the limited rights restrictions of DFARS 252.227-7015(b)(2) (June 1995) and/or subject to the '
            . 'restrictions of DFARS 227.7202-1(a) (June 1995) and DFARS 227.7202-3(a) (June 1995), as applicable for U.S. Department of '
            . 'Defense procurements and the limited rights restrictions of FAR 52.227-14 (June 1987) and/or subject to the restricted '
            . 'rights provisions of FAR 52.227-14 (June 1987) and FAR 52.227-19 (June 1987), as applicable, and any applicable agency '
            . 'FAR Supplements, for non-Department of Defense Federal procurements.',
        'snomed' => 'This material includes SNOMED Clinical Terms® (SNOMED CT®) which is used by permission of the International Health '
            . 'Terminology Standards Development Organisation (IHTSDO). All rights reserved. SNOMED CT®, was originally created by The '
            . 'College of American Pathologists. "SNOMED" and "SNOMED CT" are registered trademarks of the IHTSDO.',
        'loinc' => 'This product includes all, or a portion of, or is derived from content from LOINC that is subject to a license from '
            . 'Regenstrief Institute, Inc. Your use of LOINC content also is subject to this license, a copy of which is available '
            . 'https://loinc.org/license/ . The current complete LOINC distribution is available for download at http://loinc.org . '
            . 'The LOINC Table and LOINC codes are copyright © 1995-2020, Regenstrief Institute, Inc. and the Logical Observation '
            . 'Identifiers Names and Codes (LOINC) Committee. The LOINC Table, LOINC Table Core, LOINC Panels and Forms File, LOINC '
            . 'Answer File, LOINC Part File, LOINC Group File, LOINC Document Ontology File, LOINC Hierarchies, LOINC Linguistic '
            . 'Variants File, LOINC/RSNA Radiology Playbook, LOINC/IEEE Medical Device Code Mapping Table, LOINC Display Name File, '
            . 'LOINC Consumer Name File, and LOINC Change Snapshot File are copyright © 1995-2020, Regenstrief Institute, Inc. All '
            . 'rights reserved. THE LOINC TABLE (IN ALL FORMATS), LOINC TABLE CORE, LOINC PANELS AND FORMS FILE, LOINC ANSWER FILE, '
            . 'LOINC PART FILE, LOINC GROUP FILE, LOINC DOCUMENT ONTOLOGY FILE, LOINC HIERARCHIES, LOINC LINGUISTIC VARIANTS FILE, '
            . 'LOINC/RSNA RADIOLOGY PLAYBOOK, LOINC/IEEE MEDICAL DEVICE CODE MAPPING TABLE, LOINC DISPLAY NAME FILE, LOINC CONSUMER '
            . 'NAME FILE, AND LOINC CHANGE SNAPSHOT FILE ARE PROVIDED "AS IS." ANY EXPRESS OR IMPLIED WARRANTIES ARE DISCLAIMED, '
            . 'INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE. LOINC® '
            . 'is a registered United States trademark of Regenstrief Institute, Inc. A small portion of LOINC content may include '
            . 'content (e.g., survey instruments) that is subject to copyrights owned by third parties. Such content has been mapped '
            . 'to LOINC terms under applicable copyright and terms of use. Notice of such third-party copyright and license terms '
            . 'would need to be included if such content is included.',
    ];

    /** How each code system is named to users. */
    public const NAMES = [
        'icd10' => 'ICD-10-CM',
        'cpt' => 'CPT',
        'hcpcs' => 'HCPCS',
        'snomed' => 'SNOMED CT (US Edition)',
        'rxnorm' => 'RxNorm',
        'loinc' => 'LOINC',
    ];
}
