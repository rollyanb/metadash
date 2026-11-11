# Sincronização de gastos de eventos — versão 2

Este pacote altera o fluxo de gastos para que o botão do evento crie uma execução na fila e o trabalho pesado seja feito por um worker PHP via linha de comando. A tela acompanha o andamento e mostra falhas por campanha.

## Antes de instalar

1. Faça backup dos arquivos atuais e do banco de dados.
2. Confirme que esta versão foi preparada para a mesma cópia do sistema enviada para auditoria. Se o servidor tiver alterações mais recentes, não sobrescreva sem comparar.
3. Não há conexão com o servidor de produção neste pacote; a instalação precisa ser feita no Hostinger.

## Instalação

1. Importe `migrations/2026-10-21_event_sync_queue.sql` no banco de dados da aplicação.
2. Envie os arquivos mantendo os caminhos relativos e substitua os arquivos correspondentes em `public_html`.
3. No painel de cron do Hostinger, configure `cron/process_event_sync_queue.php` para executar a cada minuto via PHP CLI. Use o caminho absoluto e a versão/caminho do PHP informados pela própria hospedagem; não use URL HTTP.
4. Mantenha `cron/sync_campaigns.php` no cron automático atual (a cada 30 minutos). Ele agora apenas agenda eventos ativos; o worker acima processa a fila.
5. Configure `cron/warm_projections.php` como CLI, conforme a frequência já usada para aquecer o cache. Chamadas por URL deixam de funcionar porque o acesso web aos scripts cron foi bloqueado.
6. Entre no painel, abra um evento e use **Sincronizar gastos**. A execução deve sair de “na fila” para o progresso das campanhas. Se o worker não estiver rodando, ela ficará na fila.

## Como a nova sincronização funciona

- Usa `date_campaign_start` até o menor entre `date_campaign_end` e hoje. Sem início configurado, usa os últimos 30 dias.
- Consulta cada campanha vinculada, verifica se pertence a uma conta vinculada ao evento e aplica a cotação da moeda dessa conta.
- Registra cada execução, o progresso e o resultado por campanha.
- Publica cada campanha somente após concluir a leitura de todas as páginas. Uma falha mantém os snapshots válidos anteriores daquela campanha; uma resposta completa sem entrega substitui os dias do período por nenhum registro.
- Exibe falhas parciais e deixa claro o período consultado.
- A projeção soma os snapshots dentro do intervalo do evento. Cache antigo aparece sinalizado até uma atualização automática ou uma nova sincronização.

## Arquivos incluídos

A pasta contém apenas os arquivos alterados e a migração SQL. Preserve os demais arquivos da aplicação. Em `event.php`, a entrega é o arquivo inteiro; mantenha customizações locais comparando antes de substituir.

## Limites e cuidados

- Não foi executado teste com a API Meta, no Hostinger ou com as credenciais de produção. Esta revisão foi estática; PHP CLI não estava disponível neste ambiente para lint.
- O pacote não consegue provar que os totais batem com o Gerenciador de Anúncios. Compare o mesmo intervalo, fuso, atribuição e contas após a instalação.
- Não inclui rotação de token Meta, senha do banco ou chaves da aplicação. Como o arquivo e o dump enviados continham segredos, faça essa rotação separadamente e remova cópias expostas.
- Não consolida ainda todas as tabelas históricas de gastos; corrige o fluxo usado pelo orçamento do evento e melhora o tratamento de erro dos outros sincronizadores.
- Se o servidor estiver abaixo de PHP 7.4 ou MySQL/MariaDB incompatível com os recursos usados no sistema, valide a compatibilidade antes de instalar.
