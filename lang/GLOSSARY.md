# Interface glossary (US-122)

Fixed terms, translated the same way on every page. The code names every text by a key (`t('str_upload_an_image')`); `lang/en` holds the English text of every key and is the only reference.

| English | Português (Brasil) | Español |
|---|---|---|
| BirdSuite (product), BirdNET (the Cornell model) | BirdSuite, BirdNET (never translated) | BirdSuite, BirdNET |
| Station | Estação | Estación |
| Detection / detections | Detecção / detecções | Detección / detecciones |
| Species (sing./pl.) | Espécie / espécies | Especie / especies |
| Now | Agora | Ahora |
| Sonogram (was Spectrogram) | Sonograma | Espectrograma |
| Live (header button) | Ao vivo | En vivo |
| By Hour / By Week | Por hora / Por semana | Por hora / Por semana |
| Species (the species list and each species page; was Species Cards) | Espécies | Especies |
| Review (the page; was Validation) | Revisão | Revisión |
| Review (verb/button) | Revisar | Revisar |
| Delete (to the Removed folder, restorable) | Excluir | Eliminar |
| Purge (erase for good) | Expurgar | Purgar |
| Purge Files | Expurgar arquivos | Purgar archivos |
| Removed folder | pasta Removed (folder name not translated) | carpeta Removed |
| Restore | Restaurar | Restaurar |
| Confidence (model score); short label Conf. | Confiança; Conf. | Confianza; Conf. |
| Min. Conf. = minimum confidence (CONFIDENCE) | Conf. mín. = confiança mínima | Conf. mín. = confianza mínima |
| Manual Conf. = manual confidence of the species (replaces Min. Conf. for it) | Conf. manual = confiança manual da espécie | Conf. manual = confianza manual de la especie |
| Suggested manual conf. (from reviews) | Conf. manual sugerida (das revisões) | Conf. manual sugerida (de las revisiones) |
| Probability of occurrence at the location (location model); short label Prob. | Probabilidade de ocorrência no local; Prob. | Probabilidad de ocurrencia en el lugar; Prob. |
| Min. Prob. = minimum probability of occurrence at the location (SF_THRESH) | Prob. mín. = probabilidade mínima de ocorrência no local | Prob. mín. = probabilidad mínima de ocurrencia en el lugar |
| Minimums (the Param. group of Now) | Mínimos | Mínimos |
| Sigm. Sens. | Sens. Sigma | Sens. Sigma |
| Rec. Length | Duração grav. | Duración grab. |
| Overlap | Sobreposição | Superposición |
| Low Prob. / Low Conf / Uncommon | Prob. baixa / Conf. baixa / Incomum | Prob. baja / Conf. baja / Poco común |
| Settings | Configurações | Ajustes |
| Theme | Tema | Tema |
| Model | Modelo | Modelo |
| Stations / Locations / Network | Estações / Localidades / Rede | Estaciones / Ubicaciones / Red |
| Repositories | Repositórios | Repositorios |
| Advanced Settings | Configurações avançadas | Ajustes avanzados |
| Notifications / Content | Notificações / Conteúdo | Notificaciones / Contenido |
| Scheduling | Agendamento | Programación |
| Tools / System | Ferramentas / Sistema | Herramientas / Sistema |
| Status / Detection Log (System) | Status / Log de detecções | Estado / Registro de detecciones |
| External Files / Backup/Restore / File Manager / Database / Console Login (Tools) | Arquivos externos / Backup/Restauração / Gerenciador de arquivos / Banco de dados / Login no console | Archivos externos / Copia/Restauración / Gestor de archivos / Base de datos / Acceso a la consola |
| Common name Language | Idioma dos nomes comuns | Idioma de los nombres comunes |
| Defaults | Padrões | Valores predeterminados |
| Lists / Allowed species / Never accept / Always accept / Night-only species (were Custom, Excluded, Whitelisted, Nocturnal) | Listas / Espécies permitidas / Nunca aceitar / Sempre aceitar / Espécies só noturnas | Listas / Especies permitidas / Nunca aceptar / Aceptar siempre / Especies solo nocturnas |
| Location (place) | Localidade | Ubicación |
| Shadow model / Official model | Modelo sombra / Modelo oficial | Modelo sombra / Modelo oficial |
| Simple / Advanced / Full | Simples / Avançado / Completo | Simple / Avanzado / Completo |
| Filter / Search | Filtro / Buscar | Filtro / Buscar |
| Save / Apply / Cancel / Close / Default | Salvar / Aplicar / Cancelar / Fechar / Padrão | Guardar / Aplicar / Cancelar / Cerrar / Predeterminado |
| Pause / Continue | Pausar / Continuar | Pausar / Continuar |
| Today / 7 days / All | Hoje / 7 dias / Tudo | Hoy / 7 días / Todo |
| Count | Contagem | Cantidad |
| Common name / Scientific name / English name | Nome popular / Nome científico / Nome em inglês | Nombre común / Nombre científico / Nombre en inglés |
| Change species / Review later / Ignore (review answers, 2026-10-22) | Alterar espécie / Revisar depois / Ignorar | Cambiar especie / Revisar después / Ignorar |
| Purge / Purge audio | Expurgar / Expurgar áudio | Purgar / Purgar audio |
| Station WiFi / hotspot | WiFi da estação / hotspot | WiFi de la estación / hotspot |

Style: one sentence per line as in English (a full stop breaks the line); short labels in tables and fields, but long
and explanatory texts always spell out confidence or probability of occurrence at the location (owner 2026-10-21); no em dash or middle dot; Brazilian
Portuguese spelling and register (você); neutral Latin-American Spanish (usted avoided: impersonal or tú).

## Page names (US-51, owner 2026-11-04)

Every help text names a page as `{menu:<view key>}`, which shows its current place in the menu (`Settings › Locations`); never write a menu path by hand.
The old page names still open their pages (aliases in `scripts/view_registry.php`).

| Was | Now | Português (Brasil) |
|---|---|---|
| Validation | Review | Revisão |
| Species Cards | Species | Espécies |
| Shadow | Model comparison | Comparação de modelos |
| Custom | Allowed species | Espécies permitidas |
| Whitelisted | Always accept | Sempre aceitar |
| Excluded | Never accept | Nunca aceitar |
| Nocturnal | Night-only species | Espécies só noturnas |
| Content | What and when | O quê e quando |
| Repositories | Sharing | Compartilhamento |
| Defaults | All settings | Todas as configurações |
| Console Login | Terminal | Terminal |
| Browse files | Files | Arquivos |
| Detection Log | Analysis log | Log da análise |
| Plan A (station WiFi) | In the field without internet: station WiFi | Em campo sem internet: WiFi da estação |
| Plan B (phone hotspot) | In the field with internet: phone hotspot | Em campo com internet: hotspot do celular |
| Low Conf / Low Prob | Uncertain / Unlikely here | Incerta / Improvável aqui |
| Spectrogram (Espectrograma) | Sonogram | Sonograma |
