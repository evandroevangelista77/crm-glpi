<?php

namespace GlpiPlugin\Crm;

use NotificationTarget;

/**
 * Destinatários e dados dos avisos de conversa.
 */
class NotificationTargetConversa extends NotificationTarget
{
    public const ATTENDANT = 5101;

    public function getEvents()
    {
        return [
            'crm_conversa_atribuida' => 'CRM: conversa atribuída a um atendente',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::ATTENDANT, 'Atendente da conversa');
    }

    public function addSpecificTargets($data, $options)
    {
        if ((int) $data['items_id'] === self::ATTENDANT && !empty($options['attendant'])) {
            $this->addToRecipientsList(['language' => '', 'users_id' => (int) $options['attendant']]);
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $f = $this->obj->fields;
        $this->data['##conversa.name##'] = (string) ($f['name'] ?: $f['numero_wa']);
        $this->data['##conversa.numero##'] = (string) $f['numero_wa'];
        $this->data['##conversa.etapa##'] = (string) (Etapa::all()[(int) $f['plugin_crm_etapas_id']]['name'] ?? '—');
        global $CFG_GLPI;
        $this->data['##conversa.url##'] = rtrim((string) $CFG_GLPI['url_base'], '/') . Menu::page('/front/conversas.php?id=' . (int) $f['id']);
        $this->data['##conversa.attendant##'] = Conversa::attendantName((int) $f['users_id_attendant']);
    }

    public function getTags()
    {
        $tags = ['name' => 'Contato', 'numero' => 'Número', 'etapa' => 'Etapa do funil', 'attendant' => 'Atendente', 'url' => 'Link da conversa'];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "conversa.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }
}
