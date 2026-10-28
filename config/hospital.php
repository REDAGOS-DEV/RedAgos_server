<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Blood Bank Inventory Sheet
    |--------------------------------------------------------------------------
    |
    | The hospital blood bank's paper inventory form, as the owner handed it
    | over (2026-10-28): a CROSSMATCHED and an UNCROSSMATCHED table of Whole
    | Blood and Packed RBC bags, each with its expiry. The document control
    | line printed at its foot is the issuing hospital's own, so it is kept
    | here rather than in the view.
    |
    */

    'inventory_sheet' => [
        // Bags per column, as on the paper form.
        'rows' => 20,

        'form_code' => 'SPMC-F-BTS-38',
        'effectivity' => 'Effectivity: 07 July 2017',
        'revision' => 'Rev. 0',
    ],

];
