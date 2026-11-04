<?php

use GlpiPlugin\Crm\Conversa;
use GlpiPlugin\Crm\Documento;
use GlpiPlugin\Crm\Menu;

Session::checkLoginUser();
if (!Conversa::canView() || !Conversa::canCreate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$old = [];
if (isset($_POST['salvar'])) {
    Session::checkCSRF($_POST);
    $old = $_POST;
    try {
        $id = Conversa::createEntry($_POST, (array) ($_FILES['docs'] ?? []), (array) ($_POST['doc_type'] ?? []));
        Session::addMessageAfterRedirect('Entrada registrada.', false, INFO);
        Html::redirect(Menu::url('/front/oportunidade.php?id=' . $id));
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
    }
}

$v = fn (string $k, string $d = ''): string => Menu::e($old[$k] ?? $d);
$sel = fn (string $k, string $val, string $d = ''): string => ($old[$k] ?? $d) === $val ? ' selected' : '';
$type = isset(Conversa::CLIENT_TYPES[$old['client_type'] ?? '']) ? $old['client_type'] : 'privado';

$opts = function (array $list, string $key, string $default = '') use ($sel): string {
    $out = '';
    foreach ($list as $k => $label) {
        $out .= "<option value='$k'" . $sel($key, (string) $k, $default) . '>' . Menu::e(is_array($label) ? $label[0] : $label) . '</option>';
    }
    return $out;
};
$docOpts = '';
foreach (Documento::TYPES as $k => $label) {
    $docOpts .= "<option value='$k'>" . Menu::e($label) . '</option>';
}

Menu::header('Nova entrada', 'entradas');
echo "<div class='crm'>" . Menu::title('Nova entrada', 'entrada', Menu::button('Voltar às entradas', Menu::page('/front/entradas.php'), 'ti-arrow-left', 'btn-outline-secondary')) . "</div>";

echo "<form method='post' enctype='multipart/form-data' id='crm-entry' action='" . Menu::e(Menu::page('/front/entrada.php')) . "'>"
    . Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) . Html::hidden('salvar', ['value' => 1]);

// canal e tipo de cliente
echo "<div class='row row-cards'><div class='col-lg-8'>";
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-route me-2'></i>Canal de entrada e tipo de cliente</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-12'><label class='form-label'>Canal de entrada</label><div class='crm-channelpick'>"
    . implode('', array_map(function ($k, $label) use ($old) {
        $on = ($old['source'] ?? 'email') === $k ? ' checked' : '';
        return "<label class='crm-channelcard' style='--c:" . Conversa::SOURCE_COLORS[$k] . "'><input type='radio' name='source' value='$k'$on><span class='crm-channel-ico'><i class='ti " . Conversa::SOURCE_ICONS[$k] . "'></i></span><span>" . Menu::e($label) . "</span></label>";
    }, array_keys(Conversa::SOURCES), Conversa::SOURCES)) . "</div></div>"
    . "<div class='col-md-6'><label class='form-label'>Como será contratado</label><select class='form-select' name='negotiation_type' id='f-negotiation'>" . $opts(Conversa::NEGOTIATION, 'negotiation_type', $type === 'publico' ? 'pregao' : 'direta') . "</select></div>"
    . "<div class='col-12'><label class='form-label'>Tipo de cliente</label><div class='crm-typepick'>";
foreach (Conversa::CLIENT_TYPES as $k => [$label, $color, $icon]) {
    echo "<label class='crm-typecard' style='--c:$color'><input type='radio' name='client_type' value='$k'" . ($type === $k ? ' checked' : '') . "><i class='ti $icon'></i>"
        . "<b>" . Menu::e($label) . "</b><span>" . ($k === 'publico' ? 'Órgão público: exige o edital' : 'Empresa privada') . "</span></label>";
}
echo "</div></div></div></div></div>";

// contato e empresa
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-address-book me-2'></i>Contato e empresa</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-md-6'><label class='form-label required'>Nome do contato</label><input class='form-control' name='name' value='" . $v('name') . "' required></div>"
    . "<div class='col-md-6'><label class='form-label' id='lbl-company'>" . ($type === 'publico' ? 'Órgão / entidade' : 'Empresa') . "</label><input class='form-control' name='company' value='" . $v('company') . "'></div>"
    . "<div class='col-md-4'><label class='form-label'>CNPJ</label><input class='form-control' name='cnpj' value='" . $v('cnpj') . "' placeholder='00.000.000/0000-00'></div>"
    . "<div class='col-md-4'><label class='form-label'>E-mail</label><input class='form-control' type='email' name='email' value='" . $v('email') . "'></div>"
    . "<div class='col-md-4'><label class='form-label'>Telefone / WhatsApp</label><input class='form-control' name='numero_wa' value='" . $v('numero_wa') . "' placeholder='5511999999999'></div>"
    . "</div><div class='form-hint mt-2'>Informe ao menos o e-mail ou o telefone.</div></div></div>";

// pedido
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-file-description me-2'></i>Pedido</h3></div><div class='card-body'><div class='row g-3'>"
    . "<div class='col-12'><label class='form-label'>Assunto / necessidade</label><input class='form-control' name='subject' value='" . $v('subject') . "' placeholder='Ex.: Orçamento de 40 licenças e implantação'></div>"
    . "<div class='col-md-4'><label class='form-label'>Valor estimado (R$)</label><input class='form-control' name='value' inputmode='decimal' value='" . $v('value') . "' placeholder='0,00'></div>"
    . "<div class='col-md-4'><label class='form-label'>Prazo de resposta</label><input class='form-control' type='date' name='deadline' value='" . $v('deadline') . "'></div>"
    . "<div class='col-md-4 crm-only-publico'><label class='form-label'>Data da sessão / pregão</label><input class='form-control' type='date' name='session_date' value='" . $v('session_date') . "'></div>"
    . "<div class='col-md-6 crm-only-publico'><label class='form-label'>Nº do edital</label><input class='form-control' name='edital_number' value='" . $v('edital_number') . "' placeholder='Ex.: Pregão Eletrônico 045/2026'></div>"
    . "<div class='col-12'><label class='form-label'>Observações</label><textarea class='form-control' rows='3' name='notes'>" . $v('notes') . "</textarea></div>"
    . "</div></div></div>";
echo "</div>";

// documentos
echo "<div class='col-lg-4'><div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-paperclip me-2'></i>Documentos</h3></div><div class='card-body'>"
    . "<div class='alert alert-warning crm-only-publico py-2'><i class='ti ti-gavel me-1'></i><b>Cliente público:</b> anexe o <b>edital</b> para registrar a entrada.</div>"
    . "<p class='text-secondary small'>Pedido de orçamento, edital, orçamento, proposta técnica, pedido de compra, contrato. Até 20 MB por arquivo (PDF, Office, imagem, e-mail, zip).</p>"
    . "<div id='crm-docrows'></div><button type='button' class='btn btn-outline-primary w-100 mt-2' id='crm-adddoc'><i class='ti ti-plus me-1'></i>Adicionar documento</button>"
    . "<template id='crm-docrow'><div class='crm-docrow'><select class='form-select form-select-sm' name='doc_type[]'>$docOpts</select>"
    . "<input class='form-control form-control-sm' type='file' name='docs[]'><button type='button' class='btn btn-sm btn-outline-danger' data-rm><i class='ti ti-x'></i></button></div></template>"
    . "</div></div>"
    . "<div class='card'><div class='card-body'><button class='btn btn-primary w-100'><i class='ti ti-device-floppy me-1'></i>Registrar entrada</button>"
    . "<div class='form-hint mt-2'>A oportunidade entra na primeira etapa da esteira e aparece no funil.</div></div></div></div></div></form>";

echo Menu::script('entrada.js');
Html::footer();
