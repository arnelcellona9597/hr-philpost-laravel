<?php

namespace App\Enums;

enum EducationalAttainment: string
{
    case ElementaryUndergraduate = 'Elementary Undergraduate';
    case ElementaryGraduate = 'Elementary Graduate';
    case HighSchoolUndergraduate = 'High School Undergraduate';
    case HighSchoolGraduate = 'High School Graduate';
    case SeniorHighSchoolGraduate = 'Senior High School Graduate';
    case VocationalTechnical = 'Vocational / Technical';
    case CollegeUndergraduate = 'College Undergraduate';
    case CollegeGraduate = 'College Graduate';
    case MastersUnits = 'Master\'s Units';
    case MastersDegree = 'Master\'s Degree';
    case DoctoralUnits = 'Doctoral Units';
    case DoctoralDegree = 'Doctoral Degree';
}
