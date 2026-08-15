<?php

return [
    'navigation_group' => 'المعلمون والموظفون',
    'navigation' => 'تكليفات المعلمين',
    'model' => 'تكليف معلم',
    'models' => 'تكليفات المعلمين',
    'fields' => [
        'teacher' => 'المعلم',
        'class' => 'الفصل',
        'subject' => 'المادة',
        'academic_year' => 'العام الدراسي',
        'created_at' => 'تاريخ الإنشاء',
    ],
    'filters' => ['academic_year' => 'العام الدراسي', 'class' => 'الفصل'],
    'validation' => ['duplicate' => 'هذا المعلم مكلّف بالفعل بهذا الفصل والمادة في العام الدراسي المحدد.'],
    'empty_heading' => 'لا توجد تكليفات للمعلمين بعد',
    'empty_description' => 'كلّف معلماً بفصل ومادة وعام دراسي.',
];
