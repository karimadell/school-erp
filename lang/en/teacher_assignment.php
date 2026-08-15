<?php

return [
    'navigation_group' => 'Teachers and Staff',
    'navigation' => 'Teacher Assignments',
    'model' => 'Teacher Assignment',
    'models' => 'Teacher Assignments',
    'fields' => [
        'teacher' => 'Teacher',
        'class' => 'Class',
        'subject' => 'Subject',
        'academic_year' => 'Academic Year',
        'created_at' => 'Created At',
    ],
    'filters' => ['academic_year' => 'Academic Year', 'class' => 'Class'],
    'validation' => ['duplicate' => 'This teacher is already assigned to this class and subject in this academic year.'],
    'empty_heading' => 'No teacher assignments yet',
    'empty_description' => 'Assign a teacher to a class, subject, and academic year.',
];
