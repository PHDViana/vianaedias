# Fluxo de orçamentação Viana & Dias

Esta pasta é o **kit de manutenção atualizado e autoritativo** do fluxo instalado
em `vianaedias.net`. As cópias anteriores nos ficheiros de sessão são históricas:
não as usar para regenerar ou publicar os relatórios.

## Resumo de ponto

O relatório 29 mostra apenas colaboradores com o pisco **Relógio de Ponto**
ativo na ficha de RH (separador Horário de trabalho; por defeito ativo —
`deploy-attendance-clock-flag.php`, campo 6623). Dentro desses, filtra por
defeito os colaboradores com **Activo?** assinalado.
A direção pode desmarcar **Apenas ativos** para consultar também os restantes;
o filtro aplica-se tanto à lista como às folhas apresentadas. O seletor de
colaboradores aceita **vários colaboradores em simultâneo** (vazio = todos);
seleções que deixem de corresponder ao filtro de ativos são retiradas.
A tabela diária termina numa linha **Totais** com as horas previstas, extra,
faltas, horas trabalhadas e saldo do mês, coerente com os quadros-resumo.
O seletor **Vista** alterna entre o detalhe diário mensal e o **resumo anual
por mês**: no modo anual escolhe-se o ano (entre os anos com marcações) e cada
folha mostra uma linha por mês com previstas, extra, faltas, trabalhadas e
saldo, mais a linha de totais do ano. No topo, um painel **Total global**
(apenas no ecrã) agrega previstas, trabalhadas, extra, faltas e saldo de todos
os colaboradores apresentados, indicando os excluídos por erros de configuração;
**Trabalho extraordinário** e **Faltas** abrem popups com a discriminação por
colaborador (e por tipo de ausência, nas faltas).
Meses futuros ficam a «—» e o mês em
curso apresenta os dias fechados. Meses anteriores a março de 2026 não são
considerados (início dos registos de ponto): aparecem a «—» e fora dos totais.
Dias úteis já decorridos sem qualquer registo de ponto em toda a empresa são
tratados como **encerramento provável** (férias coletivas de agosto/dezembro,
pontes): não geram horas previstas nem faltas, e ficam assinalados na vista
diária e contados nas observações da vista anual. As **horas previstas contam
apenas os dias até hoje** (sem dias futuros), em ambas as vistas; meses
futuros aparecem integralmente a «—».
O seletor de mês nativo fica oculto no modo
anual, mas a escolha mantém-se ao regressar à vista mensal. Na vista anual,
o nome de cada mês é um link que abre um popup com a folha diária desse mês
(sem filtros nem ferramentas de edição), carregada do próprio relatório com
`vd_attendance_detail`, sem alterar os filtros guardados na sessão.
No ecrã, as folhas são maiores e podem ser deslocadas horizontalmente; a impressão
mantém a formatação A4 vertical. Os ficheiros instalados são
`attendance-report.php` e `attendance-report.css`.

## Custo horário de equipamento

A ficha de equipamento tem um separador **Custo do equipamento**. A referência
divide a reserva anual de renovação e os custos anuais conhecidos pelas horas
previstas de utilização. Outros custos em falta não são tratados como zero: o
resultado é parcial até serem preenchidos. A amortização contabilística é
informativa e não é somada novamente à reserva; energia e pessoas ficam de fora.
A referência não altera as tarifas das operações nem os orçamentos guardados.
A reserva financeira tem de ser constituída efetivamente.
Teste: `php equipment-hourly-cost-test.php`.

## Ajuda da configuração de operações

`manual-configuracao-operacoes.html` é o conteúdo da ajuda nativa da entidade
36, disponível na listagem e nas fichas. `deploy-operation-help.php` instala
as duas páginas sem substituir alterações existentes na ajuda.

## Operações — Tabela de configuração

O report 30 (`index.php?module=report_page/view&id=30`, entrada própria no menu)
apresenta o catálogo da entidade 36 em tabela: parâmetros principais de cada
operação e, por expansão, as subentidades **Custos** (com `#OP's Anuais`),
**Equipamentos**, **Recursos Humanos** e **Materiais**, com ligação à gestão de
Etapas. **Editar** e **＋ Adicionar** abrem os formulários nativos em popup
(`open_dialog`), preservando permissões, validações e histórico; depois de
guardar, **Atualizar tabela** recarrega os valores. Filtro "Apenas ativas no
catálogo" (persistido na sessão) e pesquisa por nome no navegador. Operações
com linhas de custo mas sem tarifa atual ficam assinaladas. Instalação
idempotente por `deploy-operation-config-table.php`; o conteúdo vive em
`operation-config-table.php`/`.css` (_CustomDEVs/quote-workflow/vd_quote_workflow).

## Configurador de Produto — recolha autónoma de pedidos

A entrada **Configurador de Produto** no menu abre diretamente o Report Designer
27, sem `path` nem registo prévio. O report não está associado a uma entidade.
A entidade 395 guarda os dados submetidos e disponibiliza os processos nativos
sobre os pedidos; mantém-se fora do menu.

### Conversão nativa do pedido em orçamento

O botão **Estado do pedido e conversão para orçamento** abre a própria ficha
nativa do pedido (`items/info`, entidade 395) como um report de resumo, sem
listar subentidades ou campos técnicos. Mostra identificação, cliente, produto,
quantidades, estado, componentes/requisitos, observações e documentos. O botão
**Criar Orçamento em rascunho** usa o processo nativo existente, com os seus
acessos, filtros e confirmação; não converte ao abrir a página. Após conversão,
mostra o orçamento associado em vez de oferecer nova criação. A edição continua
no configurador, mantendo a persistência e o histórico nativos.
O acesso ao resumo usa a mesma página e não muda o estado. Só a conversão
bem-sucedida marca **Orçamento em andamento** (o mesmo ID nativo anteriormente
denominado Convertido em orçamento); erros não avançam o estado.
`deploy-request-converted-label.php` atualiza a designação sem alterar IDs,
automatismos ou registos históricos.
Após a conversão manual bem-sucedida, o processo nativo redireciona na mesma
página para a ficha do orçamento criado (ou já associado). Em caso de erro
permanece no pedido. A conversão automática não altera a navegação.
Observações Cliente do orçamento recebe apenas o campo Observações do pedido,
incluindo quando está vazio; o resumo e avisos técnicos não são comentários
do cliente. Os requisitos continuam nos componentes, no report do pedido e no
snapshot imutável. Configuração fixada na origem está oculta na ficha através
de `item_page_hidden_fields`, sem eliminar ou alterar o conteúdo histórico.
`deploy-request-quote-presentation.php` configura essa visibilidade; a opção
`--repair-277` corrige apenas as observações do rascunho 277 a partir da origem
151, com verificação de integridade e bloqueio se o orçamento já avançou.
`deploy-hide-component-requirements.php` oculta Requisitos da etapa / componente
na listagem e na ficha nativa dos componentes, preservando o JSON para os cálculos
e a produção. Usa `listing_status` e `item_page_hidden_fields` sem apagar dados.
Antes de criar novos rascunhos, o botão abre a preparação na própria ficha do
pedido (`prepare_quote=1`). São pedidos apenas os dados técnicos em falta:
artigo/papel do catálogo, faces, dimensões, variantes e equipamentos ambíguos,
e espessura real quando não existe no catálogo. Sangria, espaçamento e flags de
preparação aproveitam os valores padrão configurados nos campos nativos;
cortante e plastificação aproveitam as escolhas do pedido.
As faces de impressão são obrigatórias no configurador para cada componente
com impressão (frente, frente e verso ou verso), validadas no navegador e na
submissão do servidor. Sem impressão e componentes de material/assemblagem não
exigem faces. Pedidos históricos sem faces não são alterados; a preparação da
conversão continua a permitir completar esse dado antes de criar o rascunho.
Duplicar pedido abre uma cópia ainda por guardar, mantendo os dados introduzidos,
mas sem ID, revisão, estado, ligação ao orçamento ou escolhas de preços.
O original e o respetivo orçamento permanecem intactos. Documentos não são
copiados e o formulário avisa para os voltar a anexar. A cópia tem de ser revista
e guardada antes da conversão num novo orçamento.
A ficha de um pedido convertido apresenta Duplicar para novo orçamento, que
abre diretamente essa cópia no configurador (`#duplicate=ID`).
A máquina de impressão
e o plano continuam a ser comparados no orçamento, não são escolhas obrigatórias
antecipadas. A descrição livre do papel é cruzada com o catálogo: descrição
normalizada, gramagem explícita e formato quando indicado. Só uma correspondência
válida e inequívoca (artigo com referência interna e dimensões) é associada
automaticamente. Não há correspondência aproximada, substituição de gramagem nem
escolha arbitrária entre formatos. Descrições genéricas ou várias correspondências
continuam a exigir escolha. O formulário identifica as associações automáticas.
Uma espessura em falta pode ser aproveitada de outro artigo da mesma referência
interna e gramagem apenas se os valores disponíveis forem concordantes.
`product-quote-preparation.php` valida as respostas no servidor e a revisão do
pedido dentro da transação de conversão. Sem os dados obrigatórios, também o
automatismo não cria o orçamento; regista o erro para completar a preparação.
As respostas são aplicadas apenas ao rascunho: o pedido e o snapshot de origem
continuam exatos. O material recebe artigo e referência interna, os parâmetros
do biadesivo seguem para a operação nativa e a espessura manual fica nos requisitos
do componente, usada pelo cálculo sem alterar o catálogo. Não são geradas versões
de preço, documentos ou ordens de produção nesta preparação. Pedidos já convertidos
continuam a abrir o mesmo orçamento, sem o reconstruir.
Nos novos rascunhos, referências Pantone separadas por vírgula originam linhas
de cor individuais quando há uma única classe de cor especial, conservando a
referência nativa `Ref. Pantone` e a contagem de cores para as chapas. Com classes
especiais combinadas, conserva-se a identificação sem inventar a distribuição.
A referência do pedido preenche a referência dos componentes quando vazia.
Referência, data de entrega pedida, expedição e instruções de acabamento aparecem
em Observações do Produto, nunca em Observações Cliente. Paginação, caixas,
livros autocopiativos e expedição continuam também nos requisitos estruturados.
Testes focados: `php product-quote-preparation-test.php`, e, no ambiente nativo,
`php product-request-conversion-test.php` e
`php product-stages-integration-test.php --requirements`.
Pedidos v2 standard com uma peça impressa, sem encadernação/assemblagem específica
nem embalamento individual, originam apenas um componente, com o título do pedido.
Corte final, embalamento do lote e entrega (se pedida) são operações desse
componente, juntamente com os acabamentos. Produtos compostos mantêm as etapas
separadas. A compactação ocorre só na conversão, sem reescrever a origem histórica.
As indicações técnicas dos acabamentos são transferidas nos requisitos e na
ficha de produção, não nas Observações Cliente.
`deploy-simple-request-quote.php` consolida apenas o rascunho 277, preservando a
peça física e recusando alterações quando existam versões ou dados nas etapas.
Validar o resumo com `php product-request-view-test.php`.

Os pedidos novos/revistos usam `request_details.workflow_version = 2`, com etapas
distintas: **componentes físicos → assemblagem → embalamento individual
(se solicitado) → embalamento do lote/expedição**. O formulário recolhe
requisitos, não escolhe tarifas nem calcula custos. A assemblagem/encadernação
é derivada do subtipo na conversão, sem escolha manual no pedido.
Ensacar/Envelopar já não aparecem em capa/miolo; Alcear agrupa as variantes manuais/máquina pelo nome da
operação-base. O orçamentista escolhe **explicitamente uma variante** por
requisito na janela **Produto, componentes e etapas**, agora acessível também
nos orçamentos originados no configurador.
Nas variantes com máquina, o equipamento também exige escolha explícita entre
os associados à operação. O custo/h é o custo total da operação; não se somam
novamente RH/equipamento quando já estiverem incluídos nesse valor.

A capa separada do miolo tem **4 páginas fixas**, mesmo com faces sem impressão.
É uma peça aberta: largura = 2 × largura fechada + lombada + abas esquerda/direita;
altura = altura fechada. As abas não aumentam as páginas do miolo. A sobrecapa
opcional é um componente impresso independente e exige a operação **Colocar
sobrecapa** na assemblagem. Capa/sobrecapa são importadas sem encadernação na
imposição da peça aberta, evitando voltar a multiplicar a sua paginação;
a encadernação é uma etapa do conjunto. Papel, impressão e plastificação
continuam independentes por componente.

**Brochuras** é uma categoria nativa própria, com subtipos **Até 4 páginas —
sem encadernação**, **Agrafadas**, **Espiral** e **Cosidas**. Menus/ementas,
em Publicidade, têm os mesmos quatro subtipos. O número total de páginas é
obrigatório, inclui as quatro páginas de capa separada e exclui a sobrecapa;
o miolo recebe total − 4. Revistas, brochuras e ementas com mais de quatro
páginas exigem subtipo com agrafes, espiral ou costura. Não se escolhe uma
encadernação arbitrária quando o subtipo não a define.
O pedido original permanece integral no snapshot de origem; os requisitos
derivados são acrescentados apenas aos componentes de orçamento e congelados
na OP, juntamente com as páginas totais e dados do modelo da caixa.

Os formatos dos restantes produtos têm uma vista 2D proporcional com cotas:
largura horizontal e altura vertical, em milímetros. Atualiza em cada alteração
das medidas e ao reabrir o pedido, tanto no formato final fechado como em cada
componente físico. Capa e sobrecapa mostram o formato aberto calculado, incluindo
abas/lombada; a vista fechada permanece independente. Sem duas medidas positivas,
mostra uma indicação de preenchimento, não dimensões presumidas. É apenas apoio
visual, sem alterar os valores ou requisitos guardados.
As medidas ficam numa coluna à esquerda e o desenho à direita, tanto no formato
fechado como nos componentes e caixas montadas. Em ecrãs até 680 px passam a uma
coluna, com medidas antes da imagem.
O bloco de formato final fechado só aparece nos produtos com capa/miolo ou
quando é pedido embalamento individual (ensacar/envelopar). Nos produtos simples,
sem esse embalamento, mostram-se apenas as medidas e desenho do componente,
sem duplicar a introdução do formato. Ocultar o bloco não elimina medidas
anteriores guardadas.
Em Economato → Capas, as dimensões do componente são introduzidas diretamente
como largura aberta e altura aberta; o desenho identifica o formato aberto.
Não se aplica o cálculo automático de capa/miolo a este produto.
O duplicado Pastas de apresentação (3827) está desativado; Capas (3711) é a
opção ativa. `deploy-merge-presentation-folders.php` preserva os registos antigos
e acrescenta a Capas as associações às operações exclusivas do duplicado, sem
alterar preços nem remover as associações históricas.
O exemplo de Papel / material pretendido adapta-se ao produto e componente:
cartolinas para capas/cartões, offset para economato, couché para publicidade,
adesivos para etiquetas/autocolantes, kraft para sacos, cartão para estruturas
e sugestões distintas para capa, miolo e sobrecapa. São apenas placeholders
indicativos, sem seleção de stock, imposição de gramagem ou alteração do texto
introduzido. Autocopiativo e canelado mantêm o material fixo existente.
Validar com `node paper-suggestion-test.cjs`.

As caixas têm catálogo próprio: **Dobrável com abas**, **Fundo automático**,
**Tampa e fundo**, **Caixa de envio**, **Rígida**, **Com berço/divisórias** e
**Personalizada** (descrição obrigatória). As três dimensões montadas alimentam
uma vista SVG proporcional, rotativa e **apenas ilustrativa**. Não é uma
planificação/cortante, nem simulação CAD da dobragem. Os desenhos de referência
mostram o corpo completo: abas superiores nas dobráveis, fundo automático
parcialmente aberto em esquema, tampa independente com rebordo, tampa articulada
e orelhas na caixa de envio, paredes espessas na rígida e divisórias interiores.
Rodar a vista aplica uma rotação de 90 graus à geometria, incluindo abas e tampas;
não troca apenas o comprimento pela largura. As faces visíveis são projetadas
e ordenadas em profundidade; o desenho ajusta-se à área sem cortar as abas.
Validar a geometria com `node box-drawing-test.cjs`.
Os desenhos técnicos de referência
podem ser anexados em PDF, SVG, DXF ou AI; não são enviados a terceiros nem
convertidos automaticamente em 3D. Caixas de cartão canelado continuam sem
impressão nem acabamentos, qualquer que seja o modelo.

Ensacar/Envelopar são validados pelo **formato final fechado**, com rotação,
estritamente inferior a 324 × 254 mm, nunca pelo formato aberto da capa.
A espessura pode ser indicada, mas a capacidade efetiva do saco/envelope exige
confirmação técnica, sem capacidade máxima inventada.

Na conversão, cada etapa torna-se uma linha/componente nativo de orçamento,
usando `kind=assembly` e metadados nativos de etapa/requisitos, sem tabelas novas.
As quantidades, tempos totais (incluindo preparação), custo/h e materiais
continuam explícitos por alternativa. O corte final e embalamento do lote
ficam uma vez nas suas etapas, não em cada componente físico. São recusadas
operações da etapa errada, requisitos por satisfazer e variantes simultâneas
da mesma operação-base. A OP recebe as etapas e requisitos congelados, o grafo
de dependências e o planeamento regressivo; não se pode iniciar uma etapa
antes de terminar as entradas.

Instalação: `migrate-configurator-native.php --schema` prepara os campos nativos;
`deploy-product-stages.php` inicializa a classificação das operações e acrescenta,
uma única vez, as associações de assemblagem para revistas/livros/caixas
aprovadas neste desenvolvimento. Preserva as outras associações e não dá
acabamentos ao cartão canelado. A operação de colocação da sobrecapa é criada
sem preços presumidos. As etapas são os textos nativos **Componente**,
**Assemblagem**, **Embalamento individual**, **Expedição** e **Suporte**.
Depois de publicar o runtime, `deploy-product-stage-costing.php` integra apenas
a descoberta por etapas no bloco nativo 364, preservando as restantes definições.
Os builders mantêm a mesma integração em futuras reconstruções.
`deploy-product-requirements.php` cria a categoria Brochuras e os subtipos
nativos de brochuras/ementas, inicializando as associações às operações uma
única vez. A elegibilidade inicial dos acabamentos de componente das brochuras
segue Revistas; os subtipos restringem a encadernação. Reexecuções preservam
as alterações posteriores às associações, preços e nomes.

Reabrir um pedido antigo apenas prepara a separação no formulário e avisa para
rever/guardar; não altera automaticamente o pedido ou os orçamentos históricos.
Apenas os novos registos por etapas têm os metadados adicionais; os snapshots
e o comportamento de orçamentos legados mantêm-se. Validar com
`product-stages-test.php`, `product-requirements-test.php`,
`product-stages-integration-test.php` (também com `--requirements`) e as suites de
recolha/conversão existentes, sempre como utilizador do alojamento nos testes
com persistência.

Depois de guardar, **Estado do pedido e conversão para orçamento** abre o registo
nativo da entidade 395. O botão de processo **Criar orçamento em rascunho**
converte o pedido; alternativamente, editar o **Estado do pedido** para
**Pronto para orçamentar** e guardar executa o processo automático nativo
`run_after_update`. A gravação no report também despacha esse motor se o pedido
já estiver pronto, por exemplo após corrigir um erro de validação.
Novos pedidos começam **Em preparação**. O formulário continua sem preços ou
simulação e não altera o estado de pedidos existentes ao reabri-los.

Ambos os processos usam `product-request-conversion.php`, a mesma transação e
o bloqueio de conversão já usado pelo configurador. Criam no máximo um orçamento
por pedido, no estado nativo **Aberto** (rascunho técnico sem cálculos), e só
depois atualizam **Convertido em orçamento** e **Orçamento associado**.
Repetições reutilizam o orçamento, mesmo após alterações do pedido; nunca
sobrescrevem o orçamento nem a origem histórica. Um pedido com vários
orçamentos históricos exige uma associação explícita ao orçamento de origem.

São transferidos cliente/contacto, produto, quantidades, componentes, dimensões,
cores, plastificação e estrutura de assemblagem. Referência, data pretendida,
contacto livre, papel solicitado, Pantones, acabamentos por componente/conjunto,
fio (tipo/cor/comprimento), cortante, expedição e observações ficam discriminados
nas observações do orçamento. O pedido completo/revisão ficam congelados nos
campos nativos imutáveis da origem. Os anexos são copiados para ficheiros nativos
independentes: eliminar o anexo do pedido não elimina a cópia do orçamento.
Não são criadas versões de cálculo, operações valorizadas, documentos comerciais,
envios, movimentos de stock ou ordens de produção.

A conversão exige nome, cliente, produto/subtipo, pelo menos um componente e
uma quantidade; impressão e acabamentos são validados. Dimensões/papel opcionais
não são inventados. Falhas revertem o orçamento e os ficheiros copiados, ficam
visíveis em **Erro na conversão** e no alerta nativo, e são registadas tecnicamente.
Os processos ficam disponíveis aos utilizadores, mas a rotina exige as permissões
nativas de atualização do pedido e criação/acesso ao orçamento e suas linhas.

Instalação idempotente, sem novas tabelas auxiliares:
`php deploy-configurator-conversion.php --install` cria campos/escolhas, botão,
ação PHP e automatismo/filtro nativos, inicialmente inativos. Publicar o runtime
pelo builder/deployer e executar `--enable` para ativar os dois processos.
Repetir `--install` volta a desativá-los até ao próximo `--enable`.
`product-request-conversion-test.php` cobre a conversão, repetição, permissões,
rollback, origem histórica e independência dos anexos; executar como utilizador
do alojamento para preservar as permissões das pastas.

O formulário está dividido em cinco partes: **Escolha o produto**,
**Identifique o pedido**, **Como pretende o produto?**, **Expedição** e
**Junte documentos**. O ponto 3 só aparece após a escolha da categoria e,
quando existirem, da subcategoria.
As categorias da lista global 24 aparecem como cartões de seleção única; só
depois de escolher uma categoria se mostram as respetivas subcategorias.
Categorias e subcategorias são apresentadas por ordem alfabética portuguesa,
sem alterar os identificadores ou a seleção guardada nos pedidos.
Em Documentos estão disponíveis Faturas, Faturas/Recibo, Recibos, Guias de
Transporte (antes Doc. Transporte), Guias de Remessa, Orçamentos, Notas de
Crédito/Débito, Notas de Encomenda, Requisições e Folhas de Obra.
As restantes categorias incluem também blocos, pastas de apresentação e pastas
de arquivo (Economato), encadernação Espiral e
Wire-O, sacos e saquetas, rótulos, convites, postais, calendários e outros
impressos correntes. As opções vêm diretamente da lista global 24, não de uma
lista duplicada no formulário. O instalador acrescenta as opções de forma
idempotente; preserva os IDs existentes e não altera pedidos ou históricos.
Para atualizar apenas esta lista, executar no servidor
`php deploy-product-configurator.php --product-types-only`.
O tipo de produto é obrigatório. A categoria **Outro** exige o campo
**Especifique**, guardado no pedido. O formulário usa uma apresentação compacta
para uso interno, com campos em três colunas nos ecrãs largos.
Não há listas de IDs, operações, construção técnica ou parametrização de máquinas.

Preencher nome do pedido, cliente (obrigatório), contacto (opcional), referência, data
pretendida, quantidades e observações. São aceites pedidos iniciais sem
componentes ou com quantidades por definir. O cliente é escolhido na tabela de
entidades 26, filtrada pelo tipo **Cliente** (campo 224, escolha 176); entidades
com múltiplos tipos são aceites se incluírem Cliente. Fornecedores/prospects sem
esse tipo não aparecem nos resultados e são recusados na submissão pelo servidor.
O campo Cliente é um dropdown pesquisável (Select2 já instalado na plataforma):
escrever pelo menos dois caracteres consulta os clientes sem botão adicional;
a seleção fica mantida ao mudar de produto ou reabrir o pedido. A pesquisa é
adiada 250 ms e pedidos anteriores são cancelados para evitar resultados desatualizados.
Cliente aparece antes de Contacto. O contacto é um dropdown pesquisável dos
registos da subentidade Contactos (90) do cliente selecionado, com nome, email e
telefone. Também permite escrever um contacto pontual, sem criar um registo no
cliente. Ao mudar de cliente, a escolha de contacto é limpa. A submissão valida
o acesso ao contacto e a sua pertença ao cliente; guarda o ID e o texto escolhido
no pedido, preservando os contactos em texto livre dos pedidos anteriores.
O pisco **Pedido interno**, na mesma linha do título Identifique o pedido, seleciona a entidade **Interno**
e bloqueia a pesquisa enquanto marcado. Ao desmarcar, limpa o cliente e permite
escolher outro; mudar de cliente limpa o contacto. O pedido continua associado
a uma entidade real do tipo Cliente, com as mesmas validações de acesso.
O instalador cria/reutiliza Interno idempotentemente, sem dados fiscais ou
contactos fictícios, através de `--internal-customer-only`.
**Documentos:** todos os descendentes da categoria Documentos (3752) são pedidos
de livros autocopiativos. A quantidade indica livros, não folhas. Indicar número
de início, conjuntos por livro (predefinição editável 50), vias por conjunto
(predefinição editável 3), uma cor de papel por via e a escolha exclusiva
**Picotado ou Colado**. A primeira via começa em Branco, também editável.
O papel dos componentes impressos é Autocopiativo, fixado pelo servidor.
As vias de cada conjunto partilham o mesmo número:
`fim = início + quantidade de livros × conjuntos por livro − 1`.
O formulário mostra um fim por quantidade proposta e preserva zeros iniciais;
as vias multiplicam as folhas físicas, não a sequência de numeração.
Ex.: 10 livros de 50 × 3, início 1 → fim 500; 150 folhas por livro.
100 × 2 e 50 × 4 são configurações igualmente válidas.
Os dados ficam em campos nativos do pedido; o fim calculado fica em cada linha
da subentidade Quantidades. A conversão valida novamente e discrimina unidade,
configuração, cores por via e numerações nas observações do orçamento, além da
origem histórica imutável. Pedidos antigos sem estes detalhes precisam de ser
completados antes de uma nova gravação/conversão; os orçamentos já criados não mudam.

**Aplicação de BiAdesivo (42):** ao selecionar o acabamento, preencher o número
inteiro positivo de tiras por exemplar do componente e as dimensões X/Y em mm,
ambas positivas (aceitam decimais). Os três campos aparecem na mesma linha em
desktop e em coluna no telemóvel. Desmarcar limpa-os. A validação é também feita
no servidor, mesmo para pedidos forjados. Os campos são nativos da subentidade
Acabamentos e acompanham o pedido e a discriminação no orçamento.
Antes de publicar esta versão do runtime, executar
`php migrate-configurator-native.php --schema` para preparar os campos nativos
de livros, numeração final e biadesivo; não é necessária nova migração de dados.

**Embalagem de cartão canelado:** subtipo nativo da categoria Embalagem (3743),
criado idempotentemente por `deploy-product-configurator.php --product-types-only`
e registado em `config.corrugated_type`. Exige comprimento × largura × altura
da caixa e espessura do cartão, em mm positivos (aceitam decimais). O material
é Cartão canelado e a impressão é fixada em Sem impressão; o servidor recusa
pedidos que tentem indicar cores de impressão neste subtipo.
Todas as **Caixas** (3751), incluindo as construções dobrável, rígida, tampa/fundo
e com interior/divisórias, e o subtipo canelado exigem três dimensões da caixa
montada e a escolha entre medidas **interiores úteis** ou **exteriores**.
Estas medidas são independentes das dimensões de impressão dos componentes.
Comprimento/largura/altura e tipo de medidas têm campos nativos próprios no pedido;
a espessura canelada tem campo nativo no componente, preparados por
`migrate-configurator-native.php --schema`.
Os quatro valores ficam discriminados no orçamento e no histórico da origem.
As associações de acabamentos continuam a ser geridas nas operações; adicionar
este subtipo não altera as associações existentes.

O cartão canelado não tem acabamentos. A correção das associações é feita
por `deploy-corrugated-finishing-associations.php --apply` (sem argumento,
apenas inspeciona). Nas operações que estavam associadas a toda a categoria
Embalagem, substitui a categoria pelos restantes subtipos, excluindo o canelado.
Verifica na mesma transação que a disponibilidade dos restantes produtos não
muda. Não é uma exceção fixa no formulário: a fonte continua a ser o campo
Tipo de produto aplicável das operações. Novos subtipos de Embalagem devem ter
as suas associações revistas explicitamente.

Tamanho, papel/material,
e acabamentos são opcionais. A impressão é obrigatória em cada componente
impresso: CMYK, Pantone, CMYK + Pantone, Branco ou Combinar cores.
Pantone permite várias referências separadas por vírgulas, tal como CMYK +
Pantone e as combinações. Preto fica disponível em Combinar cores; pedidos
anteriores em Preto e Branco mantêm a cor Black sem conversão para Pantone.
Há também uma escolha explícita Sem impressão para peças sem impressão.
CMYK + Pantone e combinações com Pantone/metálico exigem a identificação
das referências (até 500 caracteres). A escolha guarda os IDs nativos das
cores e o modo/referências no pedido; não inventa uma tinta por Pantone.
Os pedidos anteriores com cores definidas são apresentados no modo correspondente.
As partes de revistas
(capa e miolo) e de caixas aparecem automaticamente conforme o produto escolhido;
a relação de assemblagem é guardada internamente, não exige preenchimento técnico.
Os acabamentos são apresentados como piscos (checkboxes), permitindo selecionar
várias opções diretamente, sem dropdown nem campo de pesquisa, das operações ativas (36)
marcadas **Base/Extra = Extra** (campo 6292), independentemente do departamento
ou de serem internas/externas. Usa Nome Público quando preenchido, senão Descrição, respeitando
o acesso do utilizador. Guarda IDs e descrições no pedido, sem criar operações
de produção ou calcular custos. A submissão rejeita escolhas inativas, repetidas
ou sem acesso. O texto livre anterior mantém-se em Outros requisitos.
Escolhas antigas de operações agora Base mantêm-se apenas nos pedidos que já as
tinham, com aviso de revisão; não são oferecidas em pedidos novos.
Inclui **Envelopar**: inserir peças em envelopes, distinto de fabricar envelopes.
A operação é interna, ativa e Extra, sem custos/produtividades inventados;
formato, peças por envelope, fecho e materiais são confirmados no orçamento.
O instalador mantém-na sem duplicar através de `--finishing-catalog-only`.
Envelopar e Ensacar só podem ser selecionados com largura/altura positivas e estritamente
inferiores a 324 × 254 mm (ou na orientação rodada). Igualar qualquer limite
não é aceite. Sem dimensões ou fora do limite, o pisco fica indisponível com
explicação; escolhas antigas são mantidas para permitir retirar, mas bloqueiam
a submissão enquanto incompatíveis. A mesma regra é aplicada no servidor.

### Compatibilidade de acabamentos e plastificação

As associações são geridas nas próprias operações, no campo múltiplo
**Tipo de produto aplicável** (6442), ligado à lista global 24. O formulário e
o servidor usam esse campo como fonte autoritativa; não existem perfis fixos
de produtos no código. Associar uma categoria abrange os seus subtipos;
associar um subtipo não abrange os irmãos nem a categoria inteira.
Operações sem associação não são disponibilizadas para novas escolhas.
Mantém-se o filtro Extra/ativa/acesso, com acabamentos por componente e
encadernação no conjunto. Plastificação e Cortante também respeitam as
associações das respetivas operações, mantendo os seus controlos próprios.
Escolhas já guardadas são preservadas, com aviso se a associação for removida.

O mesmo avaliador no servidor alimenta os avisos do formulário e valida a
submissão. Agrafar, brochar e espiral são alternativas finais incompatíveis para
o mesmo produto. **Coser e brochar podem coexistir**. Diferenças da encadernação
indicada no subtipo, múltiplos cortes especiais e
combinações de película com verniz/termoestampagem/colagem são avisos de revisão
técnica, não proibições. As regras não certificam a compatibilidade física de
materiais, espessuras, películas ou equipamentos: isso é confirmado no orçamento.

Cada componente impresso tem um pisco **Plastificação**. Ao marcar, aparecem
Frente e Verso, cada um com Sem plastificação, Brilho, Mate ou Antirrisco,
carregados das escolhas nativas 6267/6268. Pode usar tipos diferentes em cada
face; pelo menos uma face deve ter um tipo quando o pisco está marcado.
Desmarcar limpa ambas as faces. Os valores ficam nos campos nativos do componente,
sem simulação nem preferência automática por plastificação interna/externa.
Plastificar o miolo gera aviso técnico. Wire-O e argolas ainda não têm operação
própria no catálogo; os subtipos existentes são preservados e pedem especificação
nas observações, sem os converter incorretamente na operação Espiral.

A plastificação tem o controlo próprio acima, mesmo quando as operações
correspondentes estão marcadas como Base; não reaparece na lista de Extras.
O instalador cria idempotentemente **Produção de cortante**, marcada Extra, com
nome público **Cortante — produção de novo**. Representa fabricar a ferramenta,
não executar o corte e vinco, e não altera as operações Base existentes.
É registada como serviço externo a confirmar, sem custos/produtividades
inventados; a seleção avisa para confirmar desenho, fornecedor, custo e prazo
no orçamento. Executar `php deploy-product-configurator.php --finishing-catalog-only`
para instalar apenas esta operação e a sua referência no registo do configurador.
O pisco Cortante permite agora escolher Novo ou Usado por componente.
Novo associa a operação Extra Produção de cortante; Usado exige um número
(até 100 caracteres) e não associa produção de ferramenta nova. Ambos ficam
guardados no pedido; o usado gera aviso para confirmar estado e adequação.
Desmarcar Cortante retira a opção e a associação à produção do cortante.
Plastificação integra a secção Acabamentos. Os labels Frente e Verso aparecem
à esquerda dos respetivos dropdowns, sem nota de ajuda por baixo.
Colocar Fio (operação Extra 36) pode ser associado a Cartazes e outros produtos
no campo Tipo de produto aplicável da operação. Ao selecionar este
acabamento, é obrigatório indicar tipo de fio e cor (até 500 caracteres cada)
e comprimento do fio em mm, superior a zero (aceita decimais).
Estes dados ficam guardados no acabamento do pedido; desmarcar limpa-os.
Tipo, cor e comprimento aparecem na mesma linha, abaixo de Colocar Fio;
em ecrãs pequenos passam a uma coluna.
Pedidos antigos com fio sem estes dados exigem preenchimento ao voltar a guardar.
O pisco Cortante aparece na mesma sequência dos restantes acabamentos; as suas
opções abrem sempre por baixo dele. Os tipos de plastificação ficam ao lado
do respetivo pisco, adaptando-se à largura disponível. Sem impressão oculta
as faces de impressão, sem apagar a escolha anterior ao alternar de modo.
A escolha Frente / Frente e verso aparece a seguir às cores, na mesma linha
quando há espaço, passando para a linha seguinte em ecrãs mais estreitos.

Expedição permite escolher por radio Levantar nas instalações, Envio por Transportador
e Entrega (Sujeito a disponibilidade e confirmação). São preferências de
recolha, sem contratação de transporte ou confirmação automática de entrega.
Pode ficar por definir, mas só aceita uma opção. Pedidos antigos com várias
opções exigem uma escolha explícita ao editar, sem selecionar uma arbitrariamente.
Os valores são validados
no servidor e mantidos ao guardar/reabrir.
Pormenores de componentes são preenchidos nas Observações. Ao abrir pedidos
antigos, o texto desse campo é transferido para as Observações, identificado
pelo componente, e só persiste ao guardar. Verniz não está nas cores a combinar:
é um acabamento. Pedidos antigos com essa cor são convertidos na operação
Verniz UV, quando disponível, ou preservados como observação para revisão.

Os documentos são escolhidos no próprio formulário e só enviados ao guardar:
até 10 por pedido, 10 MB por documento, 25 MB no total. Formatos aceites: PDF,
SVG, DXF, AI (compatível com PDF/PostScript), JPEG, PNG, TIFF, DOCX, XLSX, PPTX,
ODT, ODS e ZIP. SVG exige XML válido, sem DTD/entidades, scripts, eventos ou
referências ativas/externas; DXF exige assinatura binária ou estrutura textual
reconhecível. Desenhos são sempre descarregados como anexos, nunca renderizados
diretamente. Extensão e tipo real do conteúdo
são validados no servidor. Ficheiros usam o campo de anexos nativo da subentidade
Documentos do pedido, com hash, autor e data. Os ficheiros são gravados no
armazenamento de anexos da plataforma; as referências são transacionais,
com limpeza dos novos ficheiros se a gravação falhar.
Não são publicados como ficheiros executáveis ou URLs públicas. O download
verifica as permissões do pedido e a integridade antes de entregar o documento.
Documentos guardados podem ser descarregados ao reabrir o pedido; retirar um
documento só produz efeito ao guardar as alterações.

**Submeter pedido** é a única ação de persistência: grava identificação e
requisitos em conjunto, numa transação. Não cria orçamento, OP, operações,
planos de impressão ou movimentos de stock. Uma repetição da mesma submissão
não duplica o pedido. O report permite consultar os últimos 50 pedidos e
reabrir um pedido para completar a informação; alterações concorrentes são
detetadas pela revisão. Os links diretos usam `#configuration=ID`.

### Persistência nativa do configurador

A entidade **Configurador de Produto (395)** é a origem dos dados, com campos
consultáveis para identificação, contacto, data pretendida, expedição e requisitos.
As subentidades nativas são Componentes do pedido (397), Quantidades do pedido
(398), Acabamentos do pedido (399) e Documentos do pedido (400). Os componentes
guardam dimensões, papel, impressão, cores, plastificação e cortante; os
acabamentos guardam a operação e os detalhes do fio.

**Rubricas do pedido (403)** guardam Quant., Descrição e Código (opcional) por
linha, para Cartões, Etiquetas, Cintas, Autocolantes, Expositores, Rótulos e
Tags (lista global 24: 3708, 3744, 3745, 3746, 3761, 3834, 3835). A área
**Rubricas / nomes / tamanhos** aparece no passo 2 do configurador apenas
para esses subtipos; até 500 linhas, quantidades inteiras positivas e
descrição até 200 caracteres. Com rubricas, a quantidade do pedido é a soma
(campo bloqueado) e não há quantidades alternativas; o servidor recusa
rubricas noutros tipos ou totais divergentes. Ao criar o orçamento, a lista é
copiada para as Rubricas (280) de cada componente impresso, com uma única
quantidade igual à soma; na adjudicação seguem para a OP pelo fluxo existente.

Registos de submissão (401) preservam a idempotência e Dados técnicos
complementares (402) conservam extensões técnicas/históricas que excedam um
campo de texto nativo. A estrutura técnica guarda apenas dados complementares
e tipos de serialização, não uma segunda cópia dos campos humanos.
Os IDs dos componentes/quantidades/acabamentos nativos são mantidos ao atualizar.
A configuração histórica fixada num orçamento fica nos campos nativos do orçamento,
independente das edições posteriores do pedido.

`migrate-configurator-native.php --schema` cria campos/subentidades através
das funções nativas `entities::prepare_tables` e `entities::prepare_field`,
sem DDL de tabelas auxiliares. A sequência de transição é `--lock`,
`--migrate`, publicação do runtime, verificação e `--activate`. `--unlock`
permite cancelar a manutenção antes da ativação. Após ativação, o migrador
recusa reimportar as tabelas antigas por cima dos dados nativos. `--verify`
compara integralmente configuração, revisão, anexos, submissões e origens com
as tabelas anteriores. Essas tabelas não são apagadas automaticamente: ficam
conservadas para reversão, sem leituras ou escritas pelo runtime novo.
O registo de IDs de esquema e o fluxo de avaliações já existentes são partilhados
com o restante workflow e não são alterados por esta migração dos pedidos.
Executar `--migrate` e os testes com anexos como utilizador do alojamento
(`runuser -u vianaedias -- ...`), não como root, para não criar pastas de
anexos sem permissão de escrita para o PHP web.

Não há simulações, escolhas de produção calculadas, custos, acréscimos ou
preços neste report. O processamento posterior para orçamento é uma fase
separada; as simulações continuam exclusivamente no orçamento. Não está
publicado nenhum formulário público.

Validar esta recolha com `standalone-configurator-test.php`; o instalador
`deploy-product-configurator.php` mantém entidade, report e entrada direta no
menu. O restante texto desta secção descreve o **configurador de orçamentação
existente**, não o report de recolha.

## Utilização no orçamento

O configurador está organizado em cinco abas: **Comparativo Planos Dinâmicos**,
**Papel e matérias-primas** (operações/parâmetros, cores e rubricas),
**Simular impressão · Digital × Offset** e **Planos Possíveis** (com desenhos e
dados geométricos) e **Escolhas guardadas** (listagem final, detalhes e edição
das versões por quantidade). As sugestões e o comparativo são recalculados
automaticamente quando os parâmetros correspondentes mudam; os botões manuais
“Calcular planos possíveis” e “Comparar custos das sugestões” foram removidos da
interface. O comparativo apresenta apenas resultados, sem o texto introdutório;
o botão “Atualizar orçamento” fica junto dos restantes botões, sem cabeçalho
adicional.
Cada alternativa do comparativo inclui um link **Ver plano**. Abre uma única
janela com todas as montagens, dimensões, poses e percentagem do plano fora das
peças finais (inclui pinça, sangrias e margens; não equivale ao desperdício do
filme). As margens necessárias de entrada e saída da plastificação aparecem a
vermelho, com os valores de entrada e saída permanentemente visíveis, sem depender
de passar o rato, no eixo de passagem pelo lado estreito; o desenho respeita os parâmetros
atuais e as novas gravações também fixam esses parâmetros na geometria.
Sem seleção manual, comparam-se todas as geometrias geradas, removendo apenas
duplicados exatos; não se excluem planos compactos só pela capacidade ou pelo papel.
A classificação utiliza o total completo das operações e materiais, incluindo
plastificação. Custos incompletos não recebem a indicação de melhor plano.
Depois da classificação, a tabela mostra apenas as **3 melhores alternativas por
máquina de impressão** (Versafire, PrintMaster, SpeedMaster) e por quantidade,
sempre com a melhor completa em primeiro lugar.
**Paginação:** componentes impressos com mais de 4 páginas (miolo de revistas,
brochuras, obras de livro) são calculados como P/2 folhas F/V distintas (P se só
frente), cada uma necessária uma vez por exemplar. Em cada plano cabem D folhas
diferentes (posições em Frente e Verso; metade em Tira e Retira/Cambalhota):
⌊folhas/D⌋ montagens completas com Q tiragens e uma última montagem com as folhas
restantes repetidas no plano (menos tiragens). Chapas e acerto contam por montagem;
na Versafire o acerto só conta na primeira. A linha indica, por exemplo,
«76 págs. = 38 folhas F/V em 10 montagens de até 4 folhas». A última montagem
(incompleta) também é comparada em Tira e Retira/Cambalhota no mesmo formato de
plano e folha (uma chapa por cor para as duas faces), escolhendo a de menor custo.
Quando a assemblagem que recebe o componente tem **Coser (26)**, o miolo é montado
em cadernos dobrados: páginas arredondadas a múltiplo de 4 (páginas em branco
indicadas), montagens com número par de folhas e cadernos de 4 a 32 páginas;
a linha indica «cosido: N cadernos/exemplar», e o valor fica gravado na versão
(`parameters.pagination.signatures`). A encadernação fica no
componente de assemblagem; o campo de encadernação do miolo já não bloqueia o
gerador. Capas e peças até 4 páginas continuam como peça única.
Na Versafire, **Não gerar montagens** imprime cada rubrica numa tiragem própria. As
geometrias não mudam; ao alterar a opção, os cartões Versafire de Planos Possíveis
mostram «1 rubrica por plano» e os planos por rubrica, e só o Comparativo é
recalculado (linhas «rubricas separadas»), sem gerar de novo as geometrias.
Nas rubricas, além da pesquisa conjunta limitada, compara-se uma solução por
geometria antes da classificação pelos custos completos; o ótimo global continua
não garantido.
As margens de entrada e saída da plastificação assumem **10 mm** cada quando
ausentes, tanto na descoberta automática como no cálculo. São editáveis nos
detalhes; valores explícitos, incluindo zero, prevalecem. Cada geometria tem de
acomodar as peças dentro dessas margens. Os instantâneos já guardados não são
reescritos.
O comparativo apresenta a venda antes de desconto, **Desc. cliente**,
**Desc. extra**, **Desc. total** e o **Valor final s/ IVA**.
O campo **Desconto extra (%)** pertence à entidade Orçamento (146), começa em
zero e soma ao desconto do cliente: 25% + 5% = 30%, nunca em sequência. O
documento enviado ao cliente não mostra o desconto: **P. unit.** e **Total s/ IVA**
já são valores com desconto aplicado; percentagens e decomposição ficam no
comparativo, na adjudicação e no instantâneo aprovado.
A soma acima de 100% bloqueia o cálculo/envio. É editável no formulário do
orçamento até ao primeiro envio, protegido também por trigger de base de dados
após envio ou transporte por reconciliar. Alterações posteriores à aprovação
exigem aprovar uma nova proposta antes do envio. Nesse caso, a proposta aprovada
mostra os descontos aprovados e atuais, deixa de poder ser enviada e oferece
**Aprovar novamente com o desconto atual**, que reaprova as mesmas versões; a
proposta anterior fica substituída e preservada. O mesmo aviso aparece no topo da
ficha do orçamento/componentes e no modal **Enviar ao cliente**; enquanto não for
reaprovada, o botão **Descarregar orçamento em PDF** fica desativado.
A percentagem do cliente vem do campo nativo Desconto do cliente
(5124); vazio significa zero, valores inválidos bloqueiam a geração comercial.
A proposta aprovada fixa ambas as percentagens, a soma e os preços brutos, o desconto e os
totais líquidos por alternativa no seu instantâneo imutável. No documento
comercial as colunas são **Descrição / Quantidade / P. unit. / Total s/ IVA**, com
preços líquidos; o título técnico do componente só aparece quando difere da
descrição comercial (sem repetição). A adjudicação mostra o preço antes do
desconto, as percentagens e o total descontado. As alternativas são apresentadas
por quantidade crescente, independentemente da ordem de seleção das versões;
propostas já geradas mantêm o documento original. Custos e margens técnicas não
são alterados nem recebem o desconto duas vezes. Após o primeiro envio (incluindo
envios com resultado por reconciliar), a percentagem desse orçamento é reutilizada
nas revisões seguintes: alterações posteriores na ficha do cliente não mudam a
proposta nem o desconto já enviado. Documentos antigos sem desconto preservam
zero, sem migração dos valores históricos.
O aviso “Regras técnicas verificadas” permanece visível no topo do configurador,
logo abaixo da navegação nativa do componente: informa que o resumo pode ser
guardado incompleto e que as linhas existentes não são alteradas/removidas
automaticamente. Durante a preparação, a tabela de operações pode mostrar linhas
manuais de configuração (por exemplo, máquina e margens da plastificação); estas
alimentam a simulação, mas não são operações materializadas para produção.
Projeções de versões ainda não aprovadas são preservadas como histórico, mas não
aparecem nessa tabela.
É possível guardar escolhas com linhas incompletas como rascunhos. Nos detalhes
da escolha guardada, clique numa operação/material destacado para completar os
parâmetros e guardar uma nova versão. A confirmação da versão, aprovação e envio
ao cliente exigem todas as linhas completas. A Espiral pede diâmetro, número de
voltas, artigo comprado compatível (unidade Un.) e quantidade total do material;
o custo usa o preço da última compra concluída.
O clique no nome de qualquer linha em **Detalhes** abre o formulário dessa linha,
tanto nas simulações como nas escolhas guardadas.
**Esc** fecha o pop-up que estiver à frente (também o modal nativo de versões).
Durante uma gravação em curso, o formulário aguarda o resultado antes de permitir
o fecho, para não deixar a gravação num estado desconhecido.
Numa simulação, **Guardar dados
e recalcular** grava uma nova versão em rascunho e conserva os parâmetros para o
recarregamento da página, sem confirmar/aprovar. Só depois da gravação bem-sucedida
atualiza os parâmetros e recalcula as simulações; erros de gravação mantêm o
formulário aberto. As pendências da linha aparecem no próprio formulário,
mesmo quando é possível guardar o rascunho. Numa escolha guardada, as alterações
criam uma nova versão apenas dessa escolha. A Espiral apresenta dois grupos no formulário:
**Operação / máquina** (equipamento, tempo, diâmetro e voltas) e **Material espiral**
(artigo comprado e quantidade manual). O equipamento escolhido fica fixado no
instantâneo e é usado na operação materializada após aprovação.
Nos detalhes dos custos, a origem e os dados em falta aparecem ao passar o rato
sobre o nome da operação ou material, sem ocupar uma coluna da tabela. A tabela
de escolhas guardadas ocupa toda a largura do comparativo; linhas com custos ou
vendas por completar ou com erros são destacadas. Linhas com **Venda calculada = 0**
também ficam destacadas, sem serem consideradas incompletas apenas por esse valor.
Nos cliques de impressão, o **Preço por clique / face** da operação digital
(campo 6107) é o custo interno do clique; a venda resulta do acréscimo comercial,
como nas restantes linhas (versões antigas guardadas mantêm a referência de venda).
Folhas longas contam 1 clique por face. Na Versafire (máx. 1250 × 330) o gerador
propõe sempre também planos **487 × 330 (SRA3+)**, mantendo as folhas longas como
alternativas. O filme de plastificação pode ser mais largo do que a folha (a
plastificadora corta o excesso): exige-se só a largura das peças, escolhendo o
filme compatível mais barato. Quando falta filme, a mensagem indica a causa
(sem filme desse acabamento, todos mais estreitos do que as peças, sem preço de
compra ou compra fora de metro linear). Quando uma operação tem uma causa concreta
(geometria da plastificação, cortes, embalamento, tarifa), não são acrescentadas as
mensagens genéricas derivadas (“Indique quantidade…”, “Custo interno em falta”);
margens incompatíveis da plastificação expõem a dimensão necessária e o
comprimento útil do plano.
Nos detalhes, **Custo manual (€)** surge imediatamente depois de **Custo calculado**.
Um valor preenchido (incluindo zero) prevalece no custo utilizado, totais e venda;
apagar o valor restaura o cálculo automático. Os ajustes e o custo calculado original
ficam no instantâneo da nova versão. É possível guardar ajustes em rascunhos
incompletos, mas um preço manual não dispensa os parâmetros técnicos em falta.
O acréscimo por defeito é **75%**, tanto no novo orçamento como no cálculo sem
percentagem indicada; percentagens explícitas e versões históricas são preservadas.
As dependências descobertas e materiais associados aparecem recolhidos sob a operação principal.
Operações principais selecionadas, inferidas e obrigatórias continuam visíveis.
Operações opcionais selecionadas no componente apresentam a indicação **Extra**
junto ao nome, independentemente do preço. A indicação não se estende aos seus
materiais ou dependências automáticas nem altera o destaque por venda zero.
As tintas (CMYK e Pantone) são dependências da impressão; em produção mista,
ficam sob a fase Offset, não sob a fase Digital. Este agrupamento também se
aplica aos detalhes de versões anteriores, sem alterar os instantâneos.
**+** expande e **−** recolhe as linhas; os custos continuam todos contabilizados.
Dependências partilhadas aparecem uma única vez. Os novos instantâneos guardam
as relações por identificador; os antigos usam a classificação histórica disponível.

1. Preencher requisitos do componente, cores, papel e quantidades. O configurador
   descobre operações e dependências; não exige operações de produção previamente
   criadas. Usa apenas linhas manuais da tabela nativa como parâmetros de
   configuração, sem as tratar como operações de produção.
   A descoberta identifica separadamente operações obrigatórias, inferidas dos
   campos, dependências do catálogo, operações selecionadas explicitamente e
   alternativas de impressão. O campo **Operações extra** no componente também
   inclui as operações escolhidas e as respetivas dependências no cálculo.
   Acabamentos opcionais (como Verniz UV e BiAdesivo) podem ser selecionados nesse
   campo; a máquina de impressão é determinada pelo plano escolhido e não torna
   todas as máquinas candidatas obrigatórias. CTP e respetivas dependências só são
   exigidos numa versão quando a alternativa produz chapas. As operações calculadas
   ficam no instantâneo da versão, sem criar operações de produção na tabela nativa
   antes da aprovação. Escolhas incompletas podem ser guardadas como rascunhos,
   mas não podem ser confirmadas nem incluídas em propostas comerciais.
   A classificação e os
   preços usados ficam no instantâneo da versão, sem alterar versões guardadas
   anteriormente. A
   operação **44 — Custos administrativos** é obrigatória em todos os componentes.
   O custo unitário dessa linha é calculado por componente convertendo o volume
   anual para documentos/hora: `(#OP's Anuais + faturas anualizadas) ÷
   (220 dias × 8 h/dia)`. O custo/h total de Janine e Carla é dividido por esse
   ritmo de documentos/hora. Assim, o resultado é €/componente, não €/hora
   dividido diretamente pelo número de documentos.
   O número de OPs vem do campo **#OP's Anuais** (6624) da **tarifa atual** da
   operação 44 (subentidade Custos, respeitando a vigência), registado pela
   direção; sem valor válido, a linha fica em erro explícito, sem valores
   inventados. As faturas contam os documentos distintos de
   2025-01-01..2026-11-06, excluindo NCC, e são anualizadas pela duração
   inclusiva do período, usando a fração efetiva de cada ano civil (365 ou
   366 dias). Com 2000 OP/ano, 1758 faturas no período (950,62/ano) e
   25 €/h combinados, o ritmo é 1,6765 documentos/h e o custo é
   14,9121 €/componente (14,91 € apresentado a duas casas decimais).
   A calibração fixada no instantâneo inclui as taxas, contagens e denominador;
   dados inválidos bloqueiam o cálculo e versões históricas não são reescritas.
   A operação **Artes finais** só entra quando os ficheiros não estão prontos;
   nesse caso estima um ficheiro por componente e usa a produtividade/acerto do
   equipamento. Equipamentos alternativos com parâmetros idênticos não bloqueiam
   o cálculo.
2. Comparar os planos e alternativas. Rever acertos, materiais, consumos de tintas,
   acabamento, plastificação, cortes e embalagem. Dados indispensáveis em falta
   impedem o cálculo completo; não são substituídos por preços inventados.
3. Ajustar custos/vendas na tabela e parâmetros no diálogo manual. Acrescentar
   operações ou materiais pelos controlos nativos e recalcular. O botão
   **Detalhes** abre o cálculo por clique ou mantendo o cursor sobre ele durante
   2 segundos; fica laranja quando houver preços por completar.
4. **Gravar nova versão** preserva todas as anteriores por componente/quantidade.
   O instantâneo inclui cálculo, requisitos e catálogo/dependências, com hash de
   integridade. Pedidos repetidos com o mesmo identificador não criam duplicados.
5. Abrir **Versões / aprovação** no modal do orçamento ou do configurador; o modal
   mostra custo e venda totais e por unidade de cada versão, sempre a partir do
   instantâneo histórico. Totais incompletos aparecem como **Por completar**.
   **Consultar instantâneo nativo** abre um pop-up de leitura com linhas de custo,
   ajustes, pendências e geometria/montagens guardadas; **Fechar** ou **Esc**
   regressa ao workflow sem sair da página e sem alterar a versão.
   O modal
   apresenta apenas o conteúdo do workflow, sem a navegação da aplicação. Confirmar uma
   versão materializa os planos279, mas mantém a tabela nativa de operações vazia.
6. Escolher uma versão confirmada de cada componente para cada quantidade oferecida
   e aprovar. A aprovação materializa as operações276 a partir dos instantâneos
   aprovados, fixando proveniência, alternativa, tiragem, custos e ordem técnica.
   Inicialmente aprova o perfil **4 — Diretor Geral**; o administrador pode
   configurar perfis adicionais nesta página.
   Antes de existir documento comercial com essa versão, o administrador pode
   usar **Reabrir versão**: o estado passa a substituído, mantendo instantâneo e
   auditoria intactos para corrigir o componente e gravar uma nova versão.
   Versões incluídas em qualquer documento comercial não podem ser reabertas.
7. Abrir o documento comercial aprovado e imprimir/guardar como PDF. O documento
   segue o modelo fornecido (Orc.pdf): logótipo, número/data, cliente/morada,
   tabela das alternativas, dimensões, páginas, impressão, cores, papel,
   acabamentos, observações comerciais, condições e rodapé.
   O número comercial é **ANO/ID**, usando o ano de criação do orçamento
   (por exemplo, **2026/24**), também no assunto do email.
   Páginas/folhas/vias só aparecem quando o componente indica um valor positivo.
   Corte Final e Embalamento não entram na descrição de acabamentos.
   Cores de processo usam as iniciais **CMYK** (ou apenas as cores presentes);
   referências Pantone continuam identificadas separadamente.
   As cores aparecem junto da impressão, por exemplo **Impressão Offset (CMYK)**.
   Na identificação do orçamento, **Enviar proposta em inglês** seleciona o idioma
   das novas propostas. Preencher **Descrição comercial em inglês**, as observações
   traduzidas (quando existem no original) e **Descrição técnica para proposta em inglês**
   em cada componente; este texto técnico deve incluir dimensões, impressão/cores,
   papel e acabamentos pretendidos. Assinalar **Textos da proposta em inglês revistos**
   antes da aprovação. Termos de pagamento normalizados são traduzidos; usar o campo
   inglês próprio para condições diferentes. O idioma, textos e assunto do email
   ficam fixados na aprovação; mudar o idioma depois não altera documentos históricos.
   Os campos ingleses são opcionais ao gravar o formulário nativo; os textos necessários
   e a confirmação de revisão são exigidos apenas ao aprovar uma proposta inglesa.
   Os preços ingleses usam ponto decimal; os portugueses mantêm vírgula decimal.
   No topo da ficha do orçamento e dos componentes, **Descarregar orçamento em PDF**
   descarrega a proposta aprovada mais recente que não tenha sido substituída.
   O nome da descarga segue `VeD_Orc_ANOID_DOCUMENTO.pdf`, por exemplo
   `VeD_Orc_202624_105.pdf`.
   Sem proposta aprovada, é apresentada uma indicação para aprovar primeiro as versões.
   Cada documento no histórico também tem descarga própria, incluindo documentos anteriores.
   O PDF é gerado pelo Dompdf nativo a partir do HTML imutável, sem recalcular preços
   nem aprovar ou enviar emails.
   No PDF, o cabeçalho usa duas células de apresentação para manter saudação,
   nome e morada alinhados, sem interferência do logótipo. Esta adaptação de
   impressão não altera o HTML histórico nem os valores aprovados.
   O progresso do componente acompanha o fluxo: **5%** na criação, **50%** quando existe
   uma versão confirmada e **100%** quando a proposta é aprovada. Uma nova gravação
   que substitui a aprovação repõe 50% (ou 5% se não há versões confirmadas);
   confirmar novamente uma versão não baixa um componente já aprovado.
   **Enviar ao cliente**, também no topo, abre a confirmação dos destinatários.
   O remetente é o email e nome do utilizador que confirma o envio, exibidos na
   confirmação e fixados na auditoria do envio. Um perfil sem email válido ou nome
   bloqueia o envio, sem usar o remetente genérico como alternativa. Mantém a
   ligação SMTP configurada; o servidor de email deve permitir esse remetente.
   O email usa uma mensagem própria, em português ou inglês, com tabelas de
   apresentação e estilos inline compatíveis com clientes de email, sem o layout
   A4 do documento no corpo. Inclui obrigatoriamente o PDF aprovado como anexo,
   com o mesmo nome e gerador da descarga. O anexo temporário é eliminado após
   o transporte; uma falha na geração bloqueia o envio antes de iniciar o transporte.
   Envia o documento aprovado por email e, só depois de o transporte confirmar,
   envia pelo ClickSend configurado no campo **SMS** da identificação:
   “Viana & Dias: o orçamento ANO/ID está concluído e foi enviado por email. Obrigado.”
   Em propostas inglesas, a notificação também é inglesa.
   Sem telemóvel atribuído no campo SMS, envia apenas o email e regista a dispensa
   da notificação. Um número preenchido mas inválido exige correção antes do envio.
   Números móveis portugueses
   sem indicativo são normalizados para +351. O destinatário e texto do SMS são
   fixados antes do envio. O estado do SMS e a auditoria ficam persistidos;
   um resultado desconhecido é assinalado e nunca repetido automaticamente.
   A confirmação do operador indica aceitação do SMS, não prova de entrega ao telemóvel.
   Propostas enviadas podem ser enviadas novamente mediante confirmação explícita,
   com email e telemóvel editáveis para cada envio. Com telemóvel, cada envio inclui
   SMS; sem telemóvel, só email. Os destinatários da proposta histórica não mudam.
   Cada tentativa tem identificador único, estados de email/SMS e histórico próprios;
   repetir o mesmo pedido é bloqueado. Envios em curso ou de resultado desconhecido
   continuam bloqueados até reconciliação, sem reenvio automático.
   **Adjudicar orçamento**, no topo da ficha do orçamento, abre um modal dedicado
   com as alternativas da proposta enviada e atual, quantidades, preços unitários
   e totais sem IVA. Exige escolher uma alternativa e confirmar explicitamente.
   A referência da aceitação é facultativa, sem mínimo de caracteres. Usa a mesma aceitação do
   histórico e seleciona os planos/operações para produção. A adjudicação também
   cria automaticamente uma **Ordem de Produção nativa**, na mesma transação:
   uma falha de permissões, dados ou criação impede a confirmação parcial.
   Uma adjudicação registada é apresentada em leitura, sem repetição.
   Pode anexar um documento de aprovação opcional (PDF, JPG ou PNG, até 5 MB).
   O conteúdo e hash ficam associados à adjudicação na mesma transação, sem
   alterar a proposta comercial. A consulta posterior exige acesso ao orçamento
   e ao documento, com verificação de integridade. O botão de confirmação fica
   numa linha própria, abaixo da referência e do anexo.
   O documento de aprovação também é acrescentado ao campo **Anexos** nativo do
   orçamento, sem substituir anexos anteriores. A sincronização é idempotente,
   verifica o hash e mantém a cópia histórica da adjudicação. Aprovações anexadas
   antes desta integração são sincronizadas na publicação.
   Após adjudicação, os botões de envio/adjudicação ficam desativados e o servidor
   bloqueia ambas as ações. Só o administrador pode **Restabelecer envio e adjudicação**:
   retira a seleção dos planos/operações para produção, reabre a proposta como enviada
   e preserva o histórico e todos os anexos. Uma nova aprovação anexada não elimina
   a anterior, que continua nos anexos nativos e no arquivo histórico.
   **Quando já existe OP, o restabelecimento é bloqueado, incluindo para o
   administrador: é necessário tratar a OP primeiro.** Não apaga, cancela,
   reinicia nem duplica produção automaticamente.
   A OP fica acessível por **Abrir ordem de produção**, no orçamento. Para
   adjudicações anteriores sem OP, **Gerar ordem de produção** usa a alternativa
   já aceite, sem reabrir a adjudicação e sem enviar email/SMS. O pedido é
   idempotente e uma OP nativa previamente ligada ao orçamento bloqueia outra
   criação até verificação.
   A adjudicação exige **Data prevista de entrega**, editável. O valor inicial
   corresponde a três dias úteis após a data da adjudicação em Europe/Lisbon,
   excluindo sábados, domingos e feriados nacionais portugueses, incluindo
   Sexta-feira Santa e Corpo de Deus; não considera feriados municipais.
   A data é validada no servidor, não pode ser anterior ao dia da adjudicação
   e fica guardada na adjudicação, auditoria e campo nativo de entrega da OP.
   A escolha explícita pode ser um dia não útil: o calendário aplica-se ao
   valor por defeito, não limita acordos de entrega com o cliente.
   Adjudicações antigas com OP sem data têm, no modal da adjudicação, a opção
   **Guardar data prevista de entrega na OP**, sem reabrir nem reenviar.
   A OP nasce em **Aberto**, com progresso zero e sem iniciar tarefas.
   Com entrega definida, recebe datas previstas pelo planeamento regressivo,
   sem reservar capacidade de máquinas/pessoas.
   Herda cliente/contacto, quantidade adjudicada, descrição,
   observações, referência do orçamento e anexos, sem remover os anexos originais.
   Contém apenas os componentes das versões da alternativa aceite:
   rubricas e distribuição por montagem; planos com geometria, poses, rotação,
   pinças, sangria, desperdício e tiragens; cores/Pantone; operações, equipamentos
   e tempos; papel, chapas, provas, tintas e materiais de acabamento/embalagem.
   Os tempos nativos são minutos inteiros arredondados para cima; as horas exatas
   continuam no instantâneo. Quantidades fracionárias têm campos de precisão
   próprios e unidade, para não apresentar consumos de tinta como zero.
   **Custos administrativos** (44) permanece exclusivamente no custo do
   orçamento, sem gerar tarefa na OP. **Imposição** (43) antecede sempre
   **Corte de papel** (2), quando ambas existem, preservando a ordem relativa
   das restantes operações. **Plastificação** (13) antecede **Corte final** (9).
   As vistas existentes do Kanban 3 sem ordenação definida passam a ordenar
   pela sequência da operação; ordenações personalizadas são preservadas.
   Na criação de cada operação, **Recursos Humanos** e **Responsável** são
   copiados da configuração atual da operação (36), independentemente da idade
   do orçamento. Ficam fixados no instantâneo da tarefa e nos índices nativos
   de utilizadores, para filtros e atribuições. Alterar o catálogo não atualiza
   retroativamente as OPs. Não são enviadas notificações nesta associação.
   A entidade **Materiais** (394), filha direta de **Componentes da OP** (159),
   é preenchida na mesma transação da adjudicação/geração da OP. Inclui
   descrição, artigo (99), referência interna (281), quantidade de precisão,
   unidade, operação (170), ligação à linha original e instantâneo aprovado.
   As linhas antigas de materiais por operação (161) são preservadas:
   cada linha tem uma única projeção na entidade do componente, registada em
   `app_vd_component_material_rows`, não um consumo adicional.
   O manifesto histórico da OP e os instantâneos adjudicados não são reescritos.
   Os campos técnicos **Dados aprovados de origem (JSON)** ficam ocultos
   nas fichas nativas de componentes, operações, cores e materiais, através
   de `item_page_hidden_fields`, preservando os campos já ocultos e os dados.
   Os materiais estão na aba **Materiais** do componente, sem bloco agregado
   no topo da ficha. O relatório imprime cada material uma vez.
   A reexecução verifica as ligações e não duplica nem substitui edições;
   se um registo gerido for eliminado/movido, o conflito é explícito.
   Os gráficos da imposição são apresentados na coluna **Plano** da listagem
   de planos do componente e na ficha do plano, substituindo o JSON visível,
   com grelha, distribuição das rubricas, rotação, área útil, pinças e viragem,
   seguindo a geometria do visualizador do configurador. São gerados dos
   instantâneos de origem verificados, incluindo os planos de OPs já criadas,
   sem modificar o histórico nem recalcular o plano.
   Na abertura da OP, o resumo agregado de materiais/gráficos não é inserido.
   Os avisos de origem da OP e materiais sem artigo fixado aparecem no topo,
   imediatamente abaixo da navegação e antes dos dados e ações da ficha.
   O botão nativo **Imprimir ficha de produção** abre o relatório
   **Ficha de produção — resumo da OP**, na entidade 121 do Report Designer.
   Inclui cabeçalho, entrega, cliente, componentes, rubricas, cores, sequência
   de operações, materiais,
   imposição e controlo final, sem preços comerciais. Usa impressão nativa
   do navegador em A4, com cabeçalhos de tabela repetidos e gráficos preservados.
   O layout/CSS e o bloco PHP podem ser editados no Report Designer; a publicação
   preserva alterações de layout e rejeita substituir um bloco alterado sem
   reconciliação. O relatório exige ACL da OP e de todas as entidades usadas;
   se faltarem dados/acesso não produz uma ficha parcial.
   Os campos
   **Artigo previsto** (catálogo de materiais), **Referência interna prevista**,
   **Quantidade prevista** e **Unidade** não reutilizam identificadores do campo
   legado ligado ao armazém.
   Não existem reservas, saídas de stock, compras ou início automático de tarefas.
   As listagens nativas padrão mostram também artigo, referência interna,
   quantidade de precisão e unidade previstos; seleções de colunas de relatórios
   personalizados são preservadas. O Stock legado não é disponibilidade
   verificada do artigo previsto. A ligação/reserva no armazém pertence à fase
   seguinte de aprovisionamento, assinalada na ficha do material.
   Um material histórico que fixa apenas a referência interna (por exemplo,
   chapas), sem artigo de fornecedor específico, conserva essa referência e
   consumo e gera aviso para revisão antes de reservar stock; não escolhe um
   artigo atual arbitrariamente.
   Os dados provêm dos instantâneos aprovados, não de uma nova simulação nem dos
   requisitos/custos atuais. Novas versões fixam também rubricas nativas e
   metadados de atribuição das operações. Versões antigas usam as rubricas do
   cálculo aprovado; sem rubricas representa-se uma única arte com a quantidade
   aceite. Versões sem informação histórica suficiente são rejeitadas.
   Atribuições de RH/fornecedor que não estavam guardadas em versões antigas
   ficam por atribuir, sem copiar silenciosamente o catálogo atual.
   A origem, hashes, estrutura criada e pendências ficam registados em
   `app_vd_quote_production_orders`, `app_vd_quote_production_rows` e na auditoria.
   Após confirmação do envio por email, o estado nativo do orçamento passa a
   **Concluído**, na mesma transação que regista o documento enviado. Sem telemóvel
   ou com SMS não confirmado, o orçamento mantém-se concluído; um envio de email
   não confirmado não altera o estado nativo. A mudança fica registada na auditoria.
   Mantém validade de **15 dias**, apesar dos 5 dias do exemplo, e não reproduz
   a assinatura manuscrita. Papel, cores e acabamentos provêm das versões
   aprovadas; diferenças técnicas entre alternativas são mostradas separadamente.
   As imagens ficam embebidas no HTML imutável, sem depender de ficheiros futuros.
   Na impressão/PDF, o rodapé fica fixo no fundo de cada folha A4, com margem
   inferior reservada para evitar sobreposição com o conteúdo.
   Documentos já aprovados não são redesenhados retroativamente.
   O documento
   fixa preços de venda, IVA, condições de pagamento do cliente e validade de
   **15 dias**, sem custos internos. Novas versões invalidam a aprovação vigente,
   mas não alteram documentos históricos.
8. Enviar apenas após verificar destinatário e documento. É utilizado o remetente
   configurado e `users::send_email`; só o retorno booleano `true` confirma envio.
   Falha ou resultado desconhecido ficam em `send_unknown`; o orçamento fica
   bloqueado para novo envio/aprovação até reconciliação administrativa. Não existe
   repetição automática nem botão para declarar sucesso sem evidência.
9. **Registar aceitação do cliente** é uma operação interna, com evidência da
   aceitação recebida externamente; não é um portal público. A quantidade aceite
   assinala os planos/operações dessa alternativa para produção.

O fluxo pertence a **Orçamentos146**. Não utiliza MD-Propostas331/processo149.
Aplica permissões nativas por entidade, item e ascendentes, regras de acesso,
atribuições e CSRF. Triggers impedem alterar/apagar instantâneos e projeções
confirmadas pelas interfaces nativas.

## Produto, componentes e assemblagem

O botão **Produto, componentes e assemblagem**, no orçamento ou componente,
configura um único produto. Num orçamento vazio pode criar estes modelos:

| Produto | Componentes iniciais |
|---|---|
| Revista | Capa; Miolo; Assemblagem / Encadernação |
| Caixa dobrável | Corpo impresso; Assemblagem / Colagem |
| Caixa rígida | Estrutura de cartão sem impressão; Revestimento impresso; Assemblagem |
| Caixa com tampa e fundo | Tampa; Fundo; Assemblagem |
| Caixa com acessórios | Corpo impresso; Berço / divisórias sem impressão; Assemblagem |

Nos orçamentos já preenchidos, configura os componentes existentes sem duplicar
linhas. Selecione o tipo de produto e use **Adicionar assemblagem aos componentes
existentes** para acrescentar a etapa final mantendo Capa/Miolo ou outras partes.
Pode adicionar componentes impressos ou materiais/acessórios. A
assemblagem final deve receber todos os restantes componentes, sem ciclos.
O formato aberto e o cortante existente são definidos manualmente nos campos
habituais do componente; não é gerado desenho estrutural.

**Calcular automaticamente** (editor de operações e materiais da assemblagem /
embalamento) preenche, para a alternativa escolhida, cada operação com valores
editáveis: custo/h = tarifa consolidada vigente; minutos = acerto + trabalho ÷
produtividade do equipamento; equipamento pré-escolhido. Trabalho por unidade:
exemplares (jogos, livros, revistas, peças, cadernos de espiral); cortes = pilhas
× 3 e lotes = pilhas, com pilhas = exemplares × lombada ÷ 100 mm; volumes =
exemplares × peso ÷ 10 kg; entregas = 1. Serviços subcontratados (ex.: Coser)
usam preço por serviço (trabalho × preço, sem tempo de máquina). A lombada é a
espessura indicada no pedido ou, na falta, folhas × gramagem × ~1 µm/g/m²; o peso
usa formato, folhas e gramagem das versões guardadas dos componentes impressos
para essa quantidade (assemblagens encadeadas incluídas). Sem versão guardada de
um componente, a nota indica que peso/espessura não o incluem.
**Coser** é subcontratado e calculado por caderno: exemplares × cadernos dos miolos
cosidos (versão guardada, `pagination.signatures`) × tarifa vigente da operação 26
(«TESTE v2 - por caderno», 1 €/caderno indicativo). Materiais sugeridos (substituem
só sugestões anteriores do mesmo artigo/operação): **Brochar** → cola BBC POLY 133
(artigo 2828, última compra) com 0,06 g/cm² × lombada × altura; **Espiral** →
artigo de espiral duplo com preço, menor Nº cujo diâmetro (Nº × 25,4/16 mm − 1,5 mm)
cobre a lombada e com voltas ≥ ⌊(altura − 6) ÷ 8,467⌋ (passo 3:1); quantidade =
exemplares.

- **Impresso:** mantém o configurador habitual, incluindo papel, formato,
  paginação, cores, imposição, corte/vinco e acabamentos próprios. Encadernação,
  alceamento final, corte final e embalamento pertencem à assemblagem e não
  são repetidos em cada componente.
- **Material / estrutura:** não exige papel, cores nem planos de impressão.
  Pode definir unidades por produto (até quatro casas decimais).
- **Assemblagem / Encadernação:** não tem impressão/imposição. Em **Operações e
  materiais**, selecione operações reais do catálogo pela ordem de execução;
  indique minutos totais incluindo preparação, custo/h, quantidade de trabalho,
  unidade e equipamento opcional. Associe artigos às operações, indicando
  consumo total, unidade e custo unitário. Custos explicitamente indicativos,
  sem reservas nem saídas de stock. O acréscimo é aplicado ao custo.

Guarde uma versão para cada componente/alternativa e confirme/aprove no painel
habitual. Tempos, quantidades e consumos são totais para a alternativa selecionada:
ao mudar de quantidade, devem ser revistos explicitamente. A estrutura,
dependências, artigos, parâmetros e cálculos ficam fixados em cada versão.
Alterar a estrutura não reescreve versões antigas e impede confirmar/aprovar
versões com outra configuração. Reestruturar fica bloqueado após envio/OP.
Os orçamentos antigos não são convertidos automaticamente.

Na OP, a assemblagem é planeada primeiro de trás para a frente; os componentes
de entrada terminam até ao seu início, com a tolerância de um dia útil aplicada
uma única vez à entrega do produto. Mantém-se a preservação das operações
iniciadas e não se efetua planeamento de capacidade de recursos.
As dependências são copiadas para `app_vd_production_component_inputs` e para o
manifesto imutável. Triggers de base de dados bloqueiam início, conclusão,
progresso e registos de tempo da assemblagem até todas as operações de entrada
estarem concluídas (39/3790), incluindo alterações fora do Kanban.

Publicação: schema aditivo pelo instalador habitual; ficheiros pelo manifesto;
`deploy-product-costing.php` atualiza apenas o bloco 364 com CAS e EXPLAIN dos
SELECT, sem alterar o motor de imposição nem os dados reais.
`quote-product-test.php` testa os cinco modelos com transportes locais simulados,
histórico preservado, OP idempotente, datas, materiais e bloqueios SQL.

## Manutenção e validação

Não publicar ficheiros históricos isoladamente. Antes de qualquer publicação,
inspecionar alterações concorrentes e preservar os hashes CAS do kit.

```sh
node --test component-operation-costing.test.cjs setup-waste.test.cjs quote-workflow-costing.test.cjs
node build-quote-workflow.cjs
node build-operation-costing.cjs
node --test quotation-load-performance.test.cjs
```

Copiar fontes geradas, fontes do manifesto, testes e manifestos para
`/home/vianaedias/public_html/_CustomDEVs/quote-workflow/vd_workflow_deployment/`, usando SSH com
`BatchMode=yes` e `StrictHostKeyChecking=yes`. Nesse diretório:

```sh
php deploy-quote-workflow.php --schema-only
php deploy-component-materials.php --backfill
php deploy-production-plan-previews.php
php deploy-production-kanban-time.php --schema-only
php quote-workflow-domain-test.php
php production-schedule-test.php
php quote-workflow-discovery-test.php
php quote-workflow-integration-test.php
php deploy-quote-workflow.php
php deploy-production-operation-policy.php
php deploy-production-staff.php
php deploy-production-kanban-time.php
php deploy-production-duration.php
php deploy-production-gantt-schedule.php
php deploy-production-report.php
php deploy-operation-costing.php
php verify-quote-workflow.php
php quotation-load-performance-test.php
```

O teste de integração usa exclusivamente transporte simulado, cria dados isolados
e limpa-os; nunca carrega o remetente nativo. O teste HTTP usa apenas clientes
fictícios e não chama o envio. Não enviar mensagens de teste a clientes reais.
Após qualquer teste HTTP, executar `php quote-workflow-browser-fixture.php cleanup`.
`--schema-only` prepara os campos/tabelas necessários aos testes sem substituir
os ficheiros em uso. A publicação completa continua a exigir os hashes do
manifesto e baselines verificados contra a versão efetivamente publicada.
Validação da criação de OP, entrega/imposição e ficha de produção: **166 verificações de domínio, 18 de planeamento e 287 de integração**,
incluindo quantidades/rubricas, associação e precisão dos materiais, Espiral,
tintas em produção mista, múltiplos componentes/alternativas, criação idempotente,
recuperação de adjudicação anterior, rollback, anexos e bloqueio do restabelecimento.
`deploy-component-materials.php` instala a entidade nativa com as permissões
da entidade de materiais por operação, sem alterar permissões já configuradas.
Com `--backfill`, preenche materiais nas OPs deste fluxo a partir das linhas
existentes, sob lock por orçamento, com auditoria e sem duplicações. Não reabre
a adjudicação, não altera anexos/datas e não envia comunicações.
`deploy-production-plan-previews.php` substitui a apresentação JSON dos planos
gerados anteriormente pelo gráfico do instantâneo verificado. Preserva as
rubricas e o manifesto histórico, recusa apresentações alteradas manualmente
e é idempotente.
`deploy-production-operation-policy.php` corrige a sequência nas OPs deste
fluxo e retira a tarefa administrativa, preservando uma cópia integral em
`app_vd_production_excluded_operations` e a proveniência original no manifesto.
A remoção de tarefas existentes foi autorizada pelo utilizador; tarefas com
progresso ou registos dependentes bloqueiam a migração. Não altera custos,
versões, propostas nem instantâneos adjudicados. A migração gera auditoria.
`deploy-production-staff.php` preenche apenas atribuições vazias nas operações
das OPs deste fluxo, preserva atribuições manuais, sincroniza os índices nativos
e regista auditoria, sem alterar a proposta ou os instantâneos adjudicados.

### Planeamento regressivo das operações

Ao gerar a OP com entrega definida, `production-schedule.php` calcula início
(2536) e data limite (2531) pela sequência das operações de cada componente,
da última para a primeira, usando os minutos previstos (2431). A última
termina às 17:00 do dia útil anterior à entrega, deixando um dia útil de
tolerância. Usa Europe/Lisbon, segunda–sexta, 08:00–12:30 e 14:00–17:00,
excluindo os mesmos feriados nacionais do cálculo da entrega. Interrompe a
contagem durante almoço, noites, fins de semana e feriados.

Não é um escalonador de capacidade de equipamentos/pessoas: os componentes
são planeados independentemente e as durações são as fixadas no orçamento.
É possível obter início no passado se o prazo não comportar a carga prevista;
o cálculo não reduz tempos nem altera a entrega para ocultar esse problema.
Se uma adjudicação antiga não tiver entrega, não inventa datas. A ação
**Definir data prevista de entrega** planeia também as tarefas ainda não
iniciadas, na mesma transação.

`deploy-production-schedule.php --order 472` aplica o cálculo explicitamente
à OP existente: preserva tarefas com tempos registados, progresso ou estado
diferente de Aberto/Agendado. Essas tarefas continuam a ocupar a respetiva
duração na sequência teórica, mas as datas reais não são substituídas.
Operações já iniciadas sem data limite podem continuar ausentes do Gantt e
exigem revisão manual. As tarefas sem datas de execução não são marcadas como
iniciadas pelo planeamento. O instalador ativa a visualização das horas nos
campos de datas. Não altera estados, tempos reais, stock ou instantâneos
adjudicados; as mudanças de planeamento ficam na auditoria do orçamento.
`deploy-production-gantt-schedule.php` adapta apenas o Gantt 3 da entidade 170
para apresentar durações em minutos e guardar os limites exatos, sem o dia
adicional da convenção inclusiva original. Faz
backup/verificação de hashes dos ficheiros nativos. A OP472 foi validada no
browser com seis barras e horários exatos, terminando a última a 11/11/2026
às 17:00, para entrega a 12/11/2026.

`deploy-production-order-gantt.php` cria o campo calculado **Data de Início
Prevista** na OP e configura o Gantt 2 para o utilizar. Usa o menor início das
operações dos seus componentes com intervalo planeado válido; ignora datas
automáticas sem limite e, em OP sem planeamento, mantém a data de início
existente como alternativa. Sem nenhuma das duas, a OP continua sem barra.
O cálculo acompanha novas OP e alterações de planeamento sem copiar datas
para o início real (2471), sem alterar tempos nem instantâneos aprovados.
O instalador cria e verifica também a coluna física `BIGINT` exigida pelo
campo dinâmico no guardar nativo. Pode ser reexecutado para reparar a coluna
em instalações anteriores, sem alterar datas reais nem a fórmula do Gantt.
A Ficha de produção apresenta no cabeçalho o início previsto com data e hora
de Lisboa, usando o mesmo cálculo do Gantt. Sem início disponível, apresenta
**POR DEFINIR**, sem inventar uma data.
A tabela de sequência apresenta apenas a posição e o nome da operação,
sem trabalho/tempo previsto nem espaços de assinatura por operação. Quem
executa, quando e os tempos continuam registados nas operações da plataforma.
A sequência e a imposição aprovada aparecem lado a lado, em duas colunas,
também na impressão; os materiais ficam por baixo, sem duplicação.
As cores aparecem no topo da coluna das operações, antes da sequência.
A tabela de materiais usa três colunas: operação, material/referência e
quantidade com unidade. Não repete o componente, já identificado no cabeçalho
da secção. As tintas são agrupadas numa linha por operação, com nome,
quantidade e unidade de cada cor; os materiais e ligações individuais mantêm-se.
O estilo de impressão A4 compacta margens internas e espaçamentos
sem ocultar linhas; a OP472 com sete operações e nove materiais foi renderizada
em PDF A4 numa única página. OP maiores continuam a paginar, sem cortar conteúdo.
Ao gerar a OP, só cria linhas Rubricas quando existem rubricas reais fixadas
na versão do orçamento. O registo sintético do componente (id zero) é apenas
uma base interna para a imposição, nunca uma rubrica nativa da OP.
Sem rubricas, a ficha não apresenta uma tabela vazia. OP anteriores não são
reescritas automaticamente, preservando o manifesto e o histórico adjudicado.
Exceção autorizada: `remove-op472-synthetic-rubric.php` remove apenas a rubrica
artificial 605 da OP472, após confirmar ausência de rubricas na origem e que
a linha não foi editada. Arquiva a cópia nativa e audita a remoção; o manifesto
original mantém-se intacto e a regeneração/verificação reconhece esse arquivo.
A legenda do plano mantém apenas os dados técnicos, sem a explicação das cores
do gráfico nem a frase sobre a versão adjudicada.
O instalador valida quatro casos isolados, é repetível e recusa configurações
alteradas. Publicar também `deploy-production-gantt-schedule.php`: adapta a
ordenação nativa ao campo calculado e apresenta esta vista por dias, incluindo
o dia de entrega. O Gantt 2 fica só de consulta; editar o planeamento nas
operações e a entrega na OP. Os outros Gantts mantêm o comportamento anterior.

## Estimativa de tinta a partir de PDF

O botão **Análise de tinta (PDF)** no orçamento/componente permite anexar um
PDF próprio por componente, consultar cobertura tonal CMYK por página e estimar
gramas com uma versão guardada do configurador. Não altera preços, stock,
operações, adjudicação ou instantâneos. PDF e estimativas anteriores são
preservados com autor, data, hash SHA-256 do PDF e da versão, parâmetros e
resultados. A interface lista os últimos 20 de cada tipo.

Primeira versão: offset SpeedMaster (máquina 6/operação 7), uma face ou
frente/verso normal (3747/3748). Não estima digital, viragens ou planos com
números de faces diferentes. PDF até 5 MB/20 páginas, não imposto, CropBox ao
tamanho do trabalho (tolerância de 15 mm para sangria/orientação). Uma página
por rubrica/face, pela ordem das rubricas da versão, frente/verso consecutivos.
O utilizador deve confirmar a correspondência, apresentada antes do cálculo.
Quantidade por página = soma de (planos úteis + desperdício) × poses da rubrica.
Não se multiplica novamente pelas folhas de papel compradas.

Consumo = área PDF em m² × cobertura/100 × exemplares impressos × g/m².
Os resultados são **sempre indicativos**. Parâmetros iniciais: 1,5 g/m² por tinta a 100%,
10% de perda adicional e 5 g fixos por tinta ativa/plano. Cada nova estimativa
fixa os parâmetros editáveis; não altera estimativas antigas. Não representam
uma calibração da máquina/papel/tinta. RGB e transparências são interpretados
pelo Ghostscript com conversão CMYK predefinida e simulação de sobreimpressão,
não por um perfil RIP certificado. Marcas exteriores ao PDF não são incluídas.
Cores diretas detetadas por separações TIFF geram aviso e bloqueiam os gramas;
não se contabiliza a conversão equivalente CMYK como consumo real de Pantone.

O processador é **local**, sem envio de PDFs a terceiros. Publicar:

```sh
bash deploy-quote-ink-worker.sh
php deploy-quote-workflow.php
php quote-ink-test.php
sudo -u vianaedias /opt/cpanel/ea-php74/root/usr/bin/php quote-ink-test.php --worker
```

O instalador do processador, executado por root após autorização, instala
apenas o worker em `/usr/local/libexec`, o comando sem argumentos em
`/usr/local/sbin` e a regra restrita em `/etc/sudoers.d/vd-ink`. Não concede
sudo genérico nem acesso Docker ao utilizador web. Cada análise corre numa
unidade systemd transitória com utilizador dinâmico, sem rede, diretórios do
site/home inacessíveis, sistema só de leitura, sem capabilities/dispositivos,
tmpfs privado de 128 MB, memória 384 MB, CPU 35 s e tempo 45 s. Um lock impede
workers simultâneos. O motor Ghostscript é o pacote mantido pela distribuição
AlmaLinux; deve continuar a receber atualizações de segurança do sistema.
PDFs danificados, fontes substituídas e falhas do motor são recusados.

Testes específicos: 13 verificações do cálculo/validação e mais 11 no worker
real (24 no total), incluindo frente/verso, múltiplos planos, sólido CMYK, meio-tom com tolerância de
quantização de 0,25 pontos percentuais, branco, formato, cor direta,
sobreimpressão, várias páginas, limite de 20 páginas e PDF inválido. Integração cobre histórico, hashes, ACL,
associação ao componente e preservação do orçamento.

### Tempos através do Kanban das operações

A coluna **Tempo** (2445) usa **HH:MM:SS**, incluindo o total da listagem.
`deploy-production-duration.php` instala o formatador, ativado apenas pela
configuração `vd_production_duration` desse campo; os minutos numéricos da
fórmula e exportações continuam disponíveis. Intervalos de 18 s e 1 s mostram
00:00:18 e 00:00:01, com total 00:00:19. Durações superiores a 24 h não
reiniciam o contador de horas. Os timestamps existentes não são alterados.

`deploy-production-kanban-time.php` integra a alteração do estado 2425 da
entidade de operações 170 com a entidade nativa **Tempos** (171). Mantém os
outros Kanbans inalterados. O instalador guarda cópias dos ficheiros nativos e
verifica hashes antes de atualizar; o helper fica em `_CustomDEVs/quote-workflow/vd_quote_workflow/`.
Preparar o schema com `--schema-only` antes dos testes de integração.

- **Em curso** (3717) / **Reiniciado** (3725) abre um intervalo do utilizador
  que move o cartão. Repetir ou alternar esses estados não duplica o intervalo.
- **Parado** (3724) / **Concluído** (39) fecha apenas o intervalo desse
  utilizador. Os restantes estados não contam tempo: fecham eventual intervalo
  próprio como pausa, sem criar um novo. Nunca fecha tempos de outros utilizadores.
- Recomeçar após uma pausa cria um novo intervalo; o tempo pausado não conta.
  A fórmula nativa de minutos usa as horas de início/fim guardadas em segundos.
- Não presume que todos os recursos afetos trabalharam nem inventa tempos
  anteriores à instalação. Não executa os processos antigos de fim, nem
  movimentos de stock. Mantém as notificações já existentes no Kanban.
- Estado, índices, tempo e auditoria `app_vd_production_time_audit` são
  transacionais, com lock por operação e ACL nativas. Tempos duplicados em
  aberto ou falta de permissões bloqueiam a alteração com erro explícito e
  atualização do quadro.
O relatório de produção é instalado por `deploy-production-report.php`, com
`production-report-block.php`, `production-report.css` e
`production-report-navigation.php` no kit de publicação.
A aba **Ficha de produção** aparece a seguir a **Maquetes** na navegação da
OP e abre o mesmo relatório, conservando o botão de impressão. O acesso
respeita os grupos, utilizadores e filtros do Report Designer.
Ao abrir um componente pelo título na listagem da OP, o redirecionamento
nativo `redirect_after_click_heading` abre **Ficha de produção**.
A aba **Info** continua a abrir a ficha normal do componente, sem ciclo de
redirecionamento. O relatório aceita o contexto da OP ou do componente,
valida a ligação entre ambos e apresenta a ficha completa da OP.
O instalador preserva uma cópia da navegação nativa antes da alteração e
verifica o hash antes de a atualizar em publicações seguintes.
A associação ao Report Designer e hashes dos blocos fica em
`app_vd_production_report`. A impressão da OP472 foi verificada pelo endpoint
nativo: 8 operações, 9 materiais, 1 gráfico, CSS A4 e ação de impressão.

Validação desta correção: **128 testes JS, 46 verificações de domínio, 31 de
descoberta e 48 de integração**. As consultas do relatório364 foram validadas com
`EXPLAIN` e os relatórios364/365 e ficheiros geridos passaram a verificação de
integridade. O bloco do relatório364 ficou com o hash
`9dff7a45fb9d30ffc648411d6c73cd3573649b64afa3a540ea4c4114a18e4bb1`; o relatório365
ficou com `3eae9d9ff5c0444d320f994ac2412b358a78fbf94d3ce89e6cf61dff40b5cf3c`.

## Desempenho de carregamento

O carregamento inicial reutiliza os JSON já emitidos pelo relatório, sem repetir
`action=load`. **Atualizar orçamento** consulta novamente o relatório exatamente
uma vez; não reutiliza o instantâneo inicial nem uma cache de preços/stock.
Materiais com referências simples usam os dados estruturados do relatório em vez
de transferir formulários completos; referências múltiplas mantêm o caminho
nativo. As listagens nativas continuam a verificar permissões e a disponibilizar
os mesmos links de edição.

Compras por artigo/unidade e movimentos de stock são agregados em consultas
read-only, preservando a interpretação numérica das referências TEXT. A última
compra concluída (estados39/3790, quantidade positiva) continua a ser escolhida por
`date_added DESC,id DESC`, incluindo preço NULL e proveniência; não se escolhe
uma compra mais antiga para preencher um preço em falta. Não foram alterados
tipos, índices, preços históricos de catálogo ou dados de clientes.

Medição real na página25/componente30, em iframe da sessão autenticada, sem proxy:
**9,37 s → 4,49 s** até ao simulador, com conteúdo das tabelas idêntico. A
consulta de papéis passou de **790 → 96 ms**, embalagens de **292 → 27 ms**;
35 consultas existentes devolveram os mesmos resultados. Os tempos variam com
a carga do servidor. A referência SQL anterior está em
`quotation-load-query-baseline.json`; o teste PHP compara-a com as consultas
geradas atuais, sem modificar registos. `--record` é apenas para criar uma
referência nova, antes da primeira publicação, e recusa sobrescrever a existente.
Repetições após publicação: **4,55 s e 4,35 s**, novamente com tabelas idênticas,
um único carregamento do relatório e nenhum formulário de material transferido.

## Calibração de tintas offset

O custo estimado usa os **36 últimos meses civis completos**, sem prorratear
meses. Para cada cor, os kg comprados em artigos de tinta concluídos (estados
39/3790, quantidade positiva e unidade kg) são tratados como consumo estimado,
sem correção de stock. O denominador soma os contadores mensais
`contador − anterior` de todas as máquinas offset elegíveis: atualmente #5
PrintMaster e #6 SpeedMaster; #7 GTO está inativa e não tem registos no período.
As máquinas digitais #9/#56 nunca entram no denominador.

`kg por impressão da cor = kg comprados da cor ÷ impressões offset agregadas`.
Cada canal CMYK usa o seu próprio numerador e preço da última compra concluída
em €/kg; o custo CMYK total é a soma dos canais impressos, não uma divisão do
consumo total por quatro. Pantone usa o conjunto de compras Pantone em kg e o
preço médio ponderado `Σ(quantidade kg × preço €/kg) ÷ Σ(quantidade kg)`.
Cada linha Pantone ativa recebe essa taxa; não há repartição histórica por
código Pantone específico.

A quantidade automática multiplica a taxa pela quantidade de impressões offset
da simulação, incluindo acertos, passagens e frente/verso; em trabalho misto
usa apenas a fase offset. Linhas de cores ativas ficam em kg. Um preço, contador,
período ou classificação em falta bloqueia a estimativa correspondente, sem
substituir por outra unidade/artigo. Artigos amarelos identificados pelo nome
mas classificados na categoria Pantone são tratados como amarelo CMYK; as
categorias de verniz offset 219/220 não são tintas CMYK/Pantone. O artigo #2177
(toner branco digital) também é excluído apesar da categoria nativa incorreta.
Compras registadas em “Fl”, “Un.” ou “cm” são apresentadas como excluídas, sem
conversão para kg.

Cada versão gravada fixa o período, máquinas/contadores, compras, artigos e
preços que sustentam a estimativa. Uma alteração posterior é assinalada como
histórico desatualizado; quantidades/custos manuais continuam a poder substituir
a estimativa numa nova versão, sem alterar versões anteriores.
