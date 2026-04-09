<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Events;

class Events
{
    const string ST_ENTITY_FORM_PRE_FORM_BUILD = "st_entity_form_pre_build";
    const string ST_ENTITY_FORM_POST_BUILD = "st_entity_form_post_build";

    const string ST_ENTITY_PRE_SAVE  = "st_entity_pre_save";
    const string ST_ENTITY_POST_SAVE = "st_entity_post_save";

    const string ST_ENTITY_PRE_DELETE = "st_entity_pre_delete";
    const string ST_ENTITY_POST_DELETE = "st_entity_post_delete";
    const string ST_ENTITY_PRE_UPDATE = "st_entity_pre_update";
    const string ST_ENTITY_POST_UPDATE = "st_entity_post_update";

    const string ST_ENTITY_INSERT = "st_entity_insert";
    const string ST_ENTITY_UPDATE = "st_entity_update";
    const string ST_ENTITY_DELETE = "st_entity_delete";
}