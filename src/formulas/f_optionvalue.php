<?php
// formulas/f_optionvalue.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class OptionValue_Fexpr extends Fexpr {
    /** @var PaperOption */
    private $option;
    function __construct(PaperOption $option) {
        parent::__construct("optionvalue");
        $this->option = $option;
    }
    function viewable_by(Contact $user) {
        return $user->can_view_some_option($this->option);
    }
    function compile(FormulaCompiler $state) {
        $id = $this->option->id;
        $oval = "\$optvalue" . ($id < 0 ? "m" . -$id : $id);
        if ($state->check_gvar($oval)) {
            $ovv = $state->_add_option_value($this->option);
            $state->gstmt[] = "$oval = $ovv ? {$ovv}->value : null;";
        }
        return $oval;
    }
}
