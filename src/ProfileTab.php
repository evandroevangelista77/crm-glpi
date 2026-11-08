<?php

namespace GlpiPlugin\Crm;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "CRM" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static function getRights($interface = 'central')
    {
        return [
            READ => __('Read'),
            UPDATE => __('Update'),
            CREATE => __('Create'),
            PURGE => __('Delete permanently'),
            \GlpiPlugin\Crm\Conversa::READALL => 'Ver conversas de todos'
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('CRM', 0, null, 'ti ti-messages');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            [['itemtype' => self::class, 'label' => 'CRM', 'field' => 'plugin_crm_conversas']],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'CRM']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê o CRM. <b>Criar/Atualizar</b>: registra e edita. <b>Ver conversas de todos</b>: enxerga as conversas de outros atendentes.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}
