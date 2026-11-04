<?php

namespace GlpiPlugin\Crm;

/**
 * Mensagem de uma conversa. direction: "in" (cliente) ou "out" (atendente).
 * delivery (só nas "out"): enviado, pendente (sem gateway) ou falhou.
 */
class Mensagem extends Base
{
    public static function getTypeName($nb = 0)
    {
        return $nb > 1 ? 'Mensagens' : 'Mensagem';
    }
}
