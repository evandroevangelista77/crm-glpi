# Dados de demonstração

Tudo que é demonstração leva "[Demo]" no nome (no banco; as telas escondem o prefixo), para poder remover depois.

```bash
python3 gerar_demo.py                      # gera crm-demo.sql e documentos/*.pdf (só biblioteca padrão do Python)
mysql NOME_DO_BANCO_DO_GLPI < crm-demo.sql
mkdir -p /var/www/glpi/files/_plugins/crm
cp documentos/*.pdf /var/www/glpi/files/_plugins/crm/
chown -R www-data:www-data /var/www/glpi/files/_plugins/crm
```

- O plugin precisa estar instalado antes (as tabelas são criadas na instalação).
- Os atendentes de exemplo são os usuários `tech`, `normal` e `glpi`, se existirem; os que faltarem ficam como "sem atendente".
- O script apaga e recria as entradas "[Demo]" a cada carga; as suas entradas reais não são tocadas.
- Para remover tudo: apague as conversas com `name LIKE '[Demo]%'` (e as mensagens, os documentos, as atas e o histórico ligados a elas).
