<?php

namespace GlpiPlugin\Crm;

use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Cria (e remove) as notificações e os modelos de e-mail do CRM. Ficam em
 * Configurar → Notificações, editáveis como as do GLPI.
 */
class Notifications
{
    private static function definitions(): array
    {
        $foot = '<p style="color:#64748b;font-size:12px">Enviado pelo módulo de CRM do GLPI.</p>';
        return [
            ['crm_conversa_atribuida', Conversa::class, 'CRM - Conversa atribuída', [NotificationTargetConversa::ATTENDANT],
                '[CRM] Nova conversa: ##conversa.name##',
                '<p>Uma conversa foi atribuída a você.</p>'
                . '<p><strong>##conversa.name##</strong> · ##conversa.numero##<br>Etapa: ##conversa.etapa##</p>'
                . '<p><a href="##conversa.url##">Abrir a conversa</a></p>' . $foot],
        ];
    }

    public static function install(): void
    {
        foreach (self::definitions() as [$event, $itemtype, $name, $targets, $subject, $html]) {
            $n = new Notification();
            if ($n->getFromDBByCrit(['itemtype' => $itemtype, 'event' => $event])) {
                continue;
            }
            $tpl = new NotificationTemplate();
            $tplId = (int) $tpl->add(['name' => $name, 'itemtype' => $itemtype, 'comment' => 'Criado pelo plugin CRM']);
            (new NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $tplId, 'language' => '', 'subject' => $subject, 'content_html' => $html,
                'content_text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $html)))),
            ]);
            $nid = (int) $n->add(['name' => $name, 'itemtype' => $itemtype, 'event' => $event, 'entities_id' => 0, 'is_recursive' => 1, 'is_active' => 1, 'comment' => 'Criada pelo plugin CRM']);
            (new Notification_NotificationTemplate())->add(['notifications_id' => $nid, 'mode' => Notification_NotificationTemplate::MODE_MAIL, 'notificationtemplates_id' => $tplId]);
            foreach ($targets as $t) {
                (new NotificationTarget())->add(['notifications_id' => $nid, 'type' => Notification::USER_TYPE, 'items_id' => $t]);
            }
        }
    }

    public static function uninstall(): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => Notification::getTable(), 'WHERE' => ['itemtype' => Conversa::class]]) as $n) {
            (new Notification())->delete(['id' => $n['id']], true);
        }
        foreach ($DB->request(['FROM' => NotificationTemplate::getTable(), 'WHERE' => ['itemtype' => Conversa::class]]) as $t) {
            (new NotificationTemplate())->delete(['id' => $t['id']], true);
        }
    }
}
