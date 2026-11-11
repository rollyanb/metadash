<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
// O tema do MetaDash define getTheme(), getThemeClass() e echoThemeAssets().
// Carregue-o antes de renderizar a sidebar para evitar função indefinida.
require_once __DIR__ . '/includes/theme.php';
Auth::requireLogin();
if (!Auth::isAdmin() && !Auth::isChefe() && !Auth::isMarketing()) { http_response_code(403); exit('Acesso não autorizado.'); }
$db=Database::getInstance(); $uid=Auth::getUserId(); $msg='';
$base=__DIR__.'/uploads/estudio'; if(!is_dir($base)) @mkdir($base,0755,true);
$categories=['logo_casa'=>'Logo da casa/evento','logo_artista'=>'Logo do artista','fundo_imagem'=>'Fundo de imagem','fundo_video'=>'Fundo de vídeo','elemento'=>'Elemento gráfico','outro'=>'Outro'];
function esc($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
if($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['upload_media']) || isset($_POST['set_default']) || isset($_POST['delete_media']))) {
  if(!hash_equals(Auth::csrfToken(), (string)($_POST['_csrf']??''))) { http_response_code(403); exit('Token de segurança inválido. Atualize a página.'); }
  if(isset($_POST['delete_media'])) {
    $id=(int)($_POST['media_id']??0);
    try {
      $asset=$db->fetch('SELECT id,stored_path FROM studio_media WHERE id=? AND user_id=?',[$id,$uid]);
      if(!$asset) { $msg='Material não encontrado ou sem permissão para excluir.'; }
      else {
        $relative=ltrim((string)$asset['stored_path'],'/');
        $candidate=realpath(__DIR__.'/'.$relative);
        $uploadsRoot=realpath($base);
        $safeFile=$candidate && $uploadsRoot && strpos($candidate,$uploadsRoot.DIRECTORY_SEPARATOR)===0;
        $db->execute('DELETE FROM studio_media WHERE id=? AND user_id=?',[$id,$uid]);
        if($safeFile && is_file($candidate)) @unlink($candidate);
        $msg='Material excluído da biblioteca.';
      }
    } catch(Exception $e) { $msg='Não foi possível excluir o material. Verifique a conexão com o banco.'; }
  } elseif(isset($_POST['set_default'])) {
    $id=(int)($_POST['media_id']??0);
    try {
      $asset=$db->fetch('SELECT id,category FROM studio_media WHERE id=? AND user_id=?',[$id,$uid]);
      if(!$asset) $msg='Material não encontrado ou sem permissão.';
      else {
        $db->execute('UPDATE studio_media SET is_default=0 WHERE user_id=? AND category=?',[$uid,$asset['category']]);
        $db->execute('UPDATE studio_media SET is_default=1 WHERE id=? AND user_id=?',[$id,$uid]);
        $msg='Material definido como padrão para a categoria.';
      }
    } catch(Exception $e) { $msg='Atualize o banco executando migrations/2026-10-16_estudio_biblioteca.sql.'; }
  } else {
    $title=trim((string)($_POST['title']??''));
    $category=(string)($_POST['category']??'outro');
    if(!isset($categories[$category])) $category='outro';
    if(empty($_FILES['media']['tmp_name']) || $_FILES['media']['error']!==UPLOAD_ERR_OK) $msg='Selecione um arquivo válido.';
    else {
      $f=$_FILES['media']; $max=100*1024*1024;
      if($f['size']>$max) $msg='O arquivo ultrapassa o limite de 100 MB.';
      else {
        $fi=new finfo(FILEINFO_MIME_TYPE); $mime=$fi->file($f['tmp_name']);
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm'];
        if(!isset($types[$mime])) $msg='Formato não permitido. Use JPG, PNG, WEBP, GIF, MP4, MOV ou WEBM.';
        elseif(strpos($mime,'video/')===0 && !in_array($category,['fundo_video','outro'])) $msg='Para vídeo, selecione a categoria Fundo de vídeo ou Outro.';
        elseif(strpos($mime,'image/')===0 && $category==='fundo_video') $msg='A categoria Fundo de vídeo aceita apenas vídeos.';
        else {
          $name=bin2hex(random_bytes(16)).'.'.$types[$mime]; $dir=$base.'/'.date('Y/m'); if(!is_dir($dir)) @mkdir($dir,0755,true);
          if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name)) $msg='Não foi possível salvar o arquivo. Verifique as permissões da pasta uploads/estudio.';
          else {
            $rel='uploads/estudio/'.date('Y/m').'/'.$name;
            try {
              $db->insert('INSERT INTO studio_media (user_id,title,original_name,stored_path,mime_type,file_size,category,is_default,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',[$uid,$title?:$f['name'],$f['name'],$rel,$mime,(int)$f['size'],$category,0]);
              $msg='Material enviado para a categoria '.$categories[$category].'.';
            } catch(Exception $e){ @unlink($dir.'/'.$name); $msg='Banco ainda não preparado. Execute migrations/2026-10-16_estudio_biblioteca.sql.'; }
          }
        }
      }
    }
  }
}
$media=[]; $defaults=[]; try{$media=$db->fetchAll('SELECT id,title,original_name,stored_path,mime_type,file_size,category,is_default,created_at FROM studio_media WHERE user_id=? ORDER BY is_default DESC,id DESC LIMIT 200',[$uid]); foreach($media as $row){if(!empty($row['is_default']))$defaults[$row['category']]=$row;}}catch(Exception $e){}
$currentPage='estudio'; $sidebarBase='';
?><!doctype html><html lang="pt-br" class="<?=esc(getThemeClass())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Estúdio Criativo — MetaDash</title><?php echoThemeAssets($sidebarBase); ?><style>
:root{--panel:var(--sf);--line:var(--bd);--muted:var(--mt2);--accent:var(--ac);--green:var(--gn)}*{box-sizing:border-box}body{font:14px/1.5 Inter,system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--tx)}.studio-content{width:100%;max-width:1900px;margin:0 auto}.head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.eyebrow{color:var(--ac);font-size:12px;text-transform:uppercase;letter-spacing:.12em;font-weight:700}.title{font-size:28px;margin:4px 0}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;align-items:start}.model-actions{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.model-actions .field{flex:1}.model-actions .btn{white-space:nowrap}.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:18px;min-width:0}.panel h2{font-size:16px;margin:0 0 14px}.field{display:grid;gap:6px;margin-bottom:13px}.field label{color:var(--tx);font-size:12px}.field input,.field select{width:100%;background:var(--s2);color:var(--tx);border:1px solid var(--bd2);border-radius:9px;padding:10px}.btn{border:0;border-radius:9px;padding:10px 14px;background:var(--accent);color:white;font-weight:700;cursor:pointer}.btn.secondary{background:var(--s3)}.note{border:1px solid var(--bd2);border-radius:10px;padding:11px;color:var(--muted);font-size:12px}.msg{padding:10px;border-radius:8px;background:color-mix(in srgb,var(--gn) 14%,var(--sf));color:var(--gn);margin-bottom:14px}.preview-wrap{display:grid;grid-template-columns:minmax(280px,390px) minmax(360px,1fr);gap:18px;align-items:start}.canvas-wrap{background:var(--s2);border:1px solid var(--bd);border-radius:12px;overflow:hidden;width:100%;max-width:390px;margin:0;flex:none;touch-action:none}.canvas-wrap canvas{width:100%;height:auto;display:block;cursor:grab;touch-action:none}.canvas-wrap canvas.dragging{cursor:grabbing}.controls{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-content:start}.controls>.field,.controls>.note,.controls>button{min-width:0}.controls>.note{align-self:start}.controls>.field:first-child{grid-column:1/-1}.controls>button{grid-column:1/-1}.video-editor{display:grid;grid-template-columns:minmax(280px,390px) minmax(360px,1fr);gap:18px;align-items:start}.video-preview-wrap{background:var(--s2);border:1px solid var(--bd);border-radius:12px;overflow:hidden;width:100%;touch-action:none}.video-preview-wrap canvas{width:100%;height:auto;display:block;cursor:grab;touch-action:none}.video-preview-wrap canvas.dragging{cursor:grabbing}.video-controls{display:grid;gap:12px}.video-source-hidden{position:fixed;right:2px;bottom:2px;width:2px;height:2px;opacity:.02;pointer-events:none;z-index:2}.drag-help{font-size:12px;color:var(--muted);margin-top:8px;text-align:center}.library{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:14px}.library-filters{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}.lib-filter{border:1px solid var(--line);background:var(--s2);color:var(--tx);border-radius:8px;padding:7px 9px;font-size:12px;cursor:pointer}.lib-filter.active{background:color-mix(in srgb,var(--ac) 16%,var(--sf));border-color:var(--ac)}.asset-actions{display:grid;gap:5px;margin-top:7px}.asset-actions .btn{font-size:11px;padding:7px 6px}.default-mark{font-size:10px;color:#34d399;margin-top:3px}.asset{border:1px solid var(--line);border-radius:10px;padding:9px;overflow:hidden}.asset img,.asset video{width:100%;aspect-ratio:3/4;object-fit:cover;background:var(--s2);border-radius:6px}.asset[data-category="logo_casa"] img,.asset[data-category="logo_artista"] img,.asset[data-category="elemento"] img{object-fit:contain;background:repeating-conic-gradient(var(--sf) 0% 25%,var(--s2) 0% 50%) 50%/18px 18px}.asset .name{font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:5px 0}.small{font-size:11px;color:var(--muted)}.tabs{display:flex;gap:8px;margin-bottom:14px}.tab{padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:var(--sf);color:var(--tx);cursor:pointer}.tab.active{background:color-mix(in srgb,var(--ac) 16%,var(--sf));border-color:var(--ac)}.hidden{display:none!important}@media(max-width:1200px){.preview-wrap,.video-editor{grid-template-columns:minmax(240px,340px) minmax(300px,1fr)}.controls{grid-template-columns:1fr}}@media(max-width:900px){.grid,.preview-wrap,.video-editor{grid-template-columns:1fr}.canvas-wrap{max-width:min(100%,390px);margin:0 auto}.controls{grid-template-columns:1fr}.head{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="layout"><?php include __DIR__.'/includes/sidebar.php'; ?><main class="main"><div class="head"><div><div class="eyebrow">MetaDash / Criação</div><h1 class="title">Estúdio Criativo</h1><div class="muted">Monte artes e prepare peças de divulgação sem sair do painel.</div></div><span class="note">MVP integrado · PNG no navegador · MP4 depende do renderizador</span></div>
<?php if($msg):?><div class="msg"><?=esc($msg)?></div><?php endif;?>
<section class="panel model-toolbar" style="margin-bottom:16px"><h2>Modelos salvos</h2><div class="model-actions"><div class="field" style="margin:0;min-width:180px"><label for="modelName">Nome do modelo</label><input id="modelName" maxlength="80" placeholder="Ex.: Victor Lou — padrão"></div><button type="button" class="btn" id="saveModel">Salvar modelo</button><div class="field" style="margin:0;min-width:220px"><label for="savedModels">Carregar modelo</label><select id="savedModels"><option value="">Selecione um modelo...</option></select></div><button type="button" class="btn secondary" id="loadModel">Carregar</button><button type="button" class="btn secondary" id="setDefaultModel">Definir como padrão</button><button type="button" class="btn secondary" id="deleteModel" style="background:#7f1d1d">Excluir modelo</button></div><div id="modelStatus" class="small" role="status" aria-live="polite" style="margin-top:8px">Salve a composição atual para reutilizá-la nas próximas campanhas neste navegador.</div></section>
<div class="grid"><section class="panel"><h2>2. Montador de arte</h2><div class="tabs"><button class="tab active" data-tab="image">Imagem</button><button class="tab" data-tab="video">Prévia de vídeo</button></div><div id="imagePanel"><div class="preview-wrap"><div><div class="canvas-wrap"><canvas id="art" width="1080" height="1920"></canvas></div><div class="drag-help">Clique diretamente em qualquer logo ou texto e arraste para reposicionar.</div><div id="imageStatus" class="small" role="status" aria-live="polite">Aguardando imagem de fundo.</div><div class="note" style="margin-top:10px"><strong>Camadas adicionadas</strong><div id="layerList" class="small" style="margin-top:6px">Nenhum elemento gráfico adicional.</div><button type="button" class="btn secondary" id="clearLayers" style="margin-top:8px">Remover camadas adicionais</button></div></div><div class="controls"><div class="field"><label>Foto/fundo (URL da biblioteca ou arquivo local)</label><input id="bgUrl" placeholder="Selecione um arquivo da biblioteca abaixo"><input type="file" id="bgFile" accept="image/*"><button type="button" class="btn secondary" id="removeBg">Remover fundo da imagem</button><small class="small">Remove a foto e mantém um fundo escuro/degradê para a arte.</small></div><div class="field"><label>Logo da casa / evento (PNG transparente recomendado)</label><input type="file" id="houseLogoFile" accept="image/*"></div><div class="field"><label>Logo do artista (PNG transparente recomendado)</label><input type="file" id="artistLogoFile" accept="image/*"></div><div class="field"><label>Nome do artista (opcional, se não usar logo)</label><input id="artist" value="VICTOR LOU" maxlength="80"></div><div class="field"><label>Cidade (superior direito)</label><input id="city" value="LONDRINA / PR" maxlength="100"></div><div class="field"><label>Data (superior esquerdo)</label><input id="dateText" value="24 JANEIRO 2026" maxlength="100"></div><div class="field"><label>Frase / chamada</label><input id="headline" value="GARANTA SEU INGRESSO" maxlength="100"></div><div class="field"><label>Cor de destaque</label><input id="accent" type="color" value="#ffffff"></div><div class="note"><strong>Ajustes independentes</strong> — arraste os elementos na própria arte ou ajuste tamanho/altura abaixo.</div><div class="field"><label>Logo da casa — tamanho <span id="houseSizeLabel">100%</span></label><input id="houseSize" type="range" min="25" max="400" value="100"><label>Logo da casa — posição vertical <span id="houseYLabel">22%</span></label><input id="houseY" type="range" min="0" max="100" value="22"></div><div class="field"><label>Logo do artista — tamanho <span id="artistSizeLabel">100%</span></label><input id="artistSize" type="range" min="25" max="400" value="100"><label>Logo do artista — posição vertical <span id="artistYLabel">72%</span></label><input id="artistY" type="range" min="0" max="100" value="72"></div><div class="field"><label>Cidade — tamanho <span id="citySizeLabel">100%</span></label><input id="citySize" type="range" min="25" max="175" value="100"><label>Cidade — posição vertical <span id="cityYLabel">20%</span></label><input id="cityY" type="range" min="0" max="100" value="20"></div><div class="field"><label>Data — tamanho <span id="dateSizeLabel">100%</span></label><input id="dateSize" type="range" min="25" max="175" value="100"><label>Data — posição vertical <span id="dateYLabel">20%</span></label><input id="dateY" type="range" min="0" max="100" value="20"></div><div class="field"><label>Frase — tamanho <span id="phraseSizeLabel">100%</span></label><input id="phraseSize" type="range" min="25" max="175" value="100"><label>Frase — posição vertical <span id="phraseYLabel">76%</span></label><input id="phraseY" type="range" min="0" max="100" value="76"></div><button class="btn" id="downloadPng">Exportar PNG 1080×1920</button><div class="note">A prévia e o PNG usam a mesma composição. Arrastar um elemento atualiza também os controles de altura.</div></div></div></div><div id="videoPanel" class="hidden"><div class="video-editor"><div><div class="video-preview-wrap"><canvas id="videoCanvas" width="1080" height="1920"></canvas></div><div class="drag-help">Clique e arraste qualquer logo ou texto na prévia. O vídeo usa os mesmos elementos e ajustes da arte.</div></div><div class="video-controls"><div class="field"><label>Vídeo de fundo</label><input type="file" id="videoFile" accept="video/mp4,video/quicktime,video/webm"><button type="button" class="btn secondary" id="removeVideoBg">Remover vídeo/fundo</button><small class="small">Remove o vídeo e a imagem de fundo, mantendo logos e textos.</small></div><div class="field"><label>Imagem de fundo alternativa (opcional)</label><input type="file" id="videoBgImageFile" accept="image/jpeg,image/png,image/webp,image/gif"><small class="small">Usada quando não houver vídeo carregado. Também atualiza o fundo da aba Imagem.</small></div><div class="field"><label>Logo da casa / evento</label><input type="file" id="videoHouseLogoFile" accept="image/png,image/jpeg,image/webp,image/*"></div><div class="field"><label>Logo do artista</label><input type="file" id="videoArtistLogoFile" accept="image/png,image/jpeg,image/webp,image/*"></div><div class="note"><strong>Elementos compartilhados com a imagem</strong><br>As logos escolhidas aqui, nome do artista, cidade, data, frase, cores, tamanhos e posições alimentam as duas prévias. Os campos são sincronizados para você poder editar pela aba que estiver usando.</div><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn secondary" type="button" id="videoPlay">Reproduzir prévia</button><button class="btn" type="button" id="downloadVideo">Exportar vídeo</button></div><div class="note">O MP4 inclui o vídeo de fundo inteiro, o áudio original e todos os elementos sobrepostos. Mantenha esta aba aberta até o arquivo ser baixado.</div><video id="videoSource" class="video-source-hidden" muted playsinline preload="auto"></video><script src="lib/mp4-muxer.js"></script><div id="videoStatus" class="small"></div></div></div></div></section>
<section class="panel"><h2>1. Biblioteca permanente</h2><p class="muted" style="margin-top:-6px">Envie uma vez, classifique e reutilize nas próximas artes.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=esc(Auth::csrfToken())?>"><input type="hidden" name="upload_media" value="1"><div class="field"><label>Nome para identificar</label><input name="title" maxlength="150" placeholder="Ex.: Logo Parador / Fundo azul"></div><div class="field"><label>Categoria do material</label><select name="category" required><?php foreach($categories as $key=>$label):?><option value="<?=esc($key)?>"><?=esc($label)?></option><?php endforeach;?></select></div><div class="field"><label>Arquivo (máximo 100 MB)</label><input type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" required></div><button class="btn" type="submit">Enviar para biblioteca</button></form><div class="note" style="margin-top:14px">Categorias separadas: logos, fundos de imagem, fundos de vídeo e elementos gráficos. Arquivos ficam no servidor; o banco guarda nome, categoria e caminho. Use somente materiais que você tem direito de utilizar.</div><h2 style="margin-top:22px">Biblioteca de materiais</h2><div class="library-filters" id="libraryFilters"><button type="button" class="lib-filter active" data-filter="all">Todos</button><?php foreach($categories as $key=>$label):?><button type="button" class="lib-filter" data-filter="<?=esc($key)?>"><?=esc($label)?></button><?php endforeach;?></div><div class="library" id="libraryGrid"><?php foreach($media as $m): $isImage=strpos($m['mime_type'],'image/')===0;?><div class="asset" data-category="<?=esc($m['category']??'outro')?>" data-title="<?=esc(mb_strtolower($m['title'],'UTF-8'))?>"><?php if($isImage):?><img src="<?=esc($m['stored_path'])?>" alt="<?=esc($m['title'])?>" loading="lazy"><?php else:?><video src="<?=esc($m['stored_path'])?>" muted playsinline preload="metadata"></video><?php endif;?><div class="name" title="<?=esc($m['title'])?>"><?=esc($m['title'])?></div><div class="small"><?=esc($categories[$m['category']??'outro']??'Outro')?> · <?=number_format($m['file_size']/1048576,2)?> MB</div><?php if(!empty($m['is_default'])):?><div class="default-mark">✓ Material padrão</div><?php endif;?><div class="asset-actions"><button type="button" class="btn secondary use-asset" data-url="<?=esc($m['stored_path'])?>" data-kind="<?=$isImage?'image':'video'?>" data-category="<?=esc($m['category']??'outro')?>" data-title="<?=esc($m['title'])?>">Usar no editor</button><button type="button" class="btn secondary add-layer" data-url="<?=esc($m['stored_path'])?>" data-kind="<?=$isImage?'image':'video'?>" data-title="<?=esc($m['title'])?>" <?=$isImage?'':'disabled title="Somente imagens podem ser adicionadas como camada nesta versão"'?>>Adicionar como camada</button><form method="post"><input type="hidden" name="_csrf" value="<?=esc(Auth::csrfToken())?>"><input type="hidden" name="set_default" value="1"><input type="hidden" name="media_id" value="<?=(int)$m['id']?>"><button class="btn" type="submit" style="width:100%">Definir como padrão</button></form><form method="post" onsubmit="return confirm('Excluir permanentemente este material da biblioteca? Esta ação não pode ser desfeita.');"><input type="hidden" name="_csrf" value="<?=esc(Auth::csrfToken())?>"><input type="hidden" name="delete_media" value="1"><input type="hidden" name="media_id" value="<?=(int)$m['id']?>"><button class="btn secondary" type="submit" style="width:100%;background:#7f1d1d">Excluir material</button></form></div></div><?php endforeach;?></div><?php if(!$media):?><p class="muted">Sua biblioteca está vazia. Envie a primeira logo, imagem, vídeo ou elemento gráfico.</p><?php endif;?></section>
</div></div></main>
<script>
const STUDIO_DEFAULTS = <?=json_encode(array_map(function($r){return ["url"=>$r["stored_path"],"title"=>$r["title"],"mime"=>$r["mime_type"]];},$defaults),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const canvas=document.getElementById('art'),ctx=canvas.getContext('2d');
let bgImage=null,houseLogo=null,artistLogo=null,dragState=null,hitBoxes=[],graphicLayers=[],layerDrag=null; let layerCounter=0;
const val=id=>document.getElementById(id).value;
const pct=id=>Number(val(id))/100;
function drawCover(c,img){
  if(!img) return false;
  const iw=img.naturalWidth||img.videoWidth||img.width;
  const ih=img.naturalHeight||img.videoHeight||img.height;
  if(!iw||!ih) return false;
  const scale=Math.max(c.canvas.width/iw,c.canvas.height/ih),w=iw*scale,h=ih*scale;
  c.drawImage(img,(c.canvas.width-w)/2,(c.canvas.height-h)/2,w,h);
  return true;
}
function wrapLines(c,text,maxWidth,font){c.font=font;const words=(text||'').toUpperCase().split(/\s+/);let lines=[],line='';for(const word of words){const test=line?line+' '+word:word;if(c.measureText(test).width>maxWidth&&line){lines.push(line);line=word}else line=test}if(line)lines.push(line);return lines}
function drawImageElement(c,img,key,sizeId,yId,maxW,maxH,boxScale=1){if(!img)return;const factor=Number(val(sizeId))/100,ratio=Math.min(maxW*factor/img.width,maxH*factor/img.height),w=img.width*ratio,h=img.height*ratio,x=(c.canvas.width-w)/2,y=c.canvas.height*pct(yId)-h/2;c.drawImage(img,x,y,w,h);hitBoxes.push({key,x:x-18,y:y-18,w:w+36,h:h+36});}
function drawTextElement(c,key,text,x,y,maxWidth,baseFont,align,lineHeight,sizeId){if(!text)return;const scale=Number(val(sizeId))/100,fontSize=Math.round(baseFont*scale),font='900 '+fontSize+'px Arial';const lines=wrapLines(c,text,maxWidth,font),lh=lineHeight*scale;c.font=font;c.textAlign=align;c.textBaseline='middle';c.fillStyle=val('accent');c.shadowColor='rgba(0,0,0,.65)';c.shadowBlur=12;const left=align==='left'?x:align==='right'?x-maxWidth/2:x-maxWidth/2;const width=Math.min(maxWidth,Math.max(...lines.map(l=>c.measureText(l).width),30));const top=y-fontSize/2,blockH=Math.max(fontSize,lines.length*lh);lines.forEach((line,i)=>c.fillText(line,x,y+(i-(lines.length-1)/2)*lh,maxWidth));c.shadowBlur=0;c.textAlign='left';c.textBaseline='alphabetic';hitBoxes.push({key,x:(align==='left'?x:x-width/2)-22,y:top-22,w:width+44,h:blockH+44});}
function drawGraphicLayers(c){
  graphicLayers.forEach(layer=>{if(!layer.img)return;const maxW=320*layer.scale,ratio=Math.min(maxW/layer.img.width,520/layer.img.height),w=layer.img.width*ratio,h=layer.img.height*ratio,x=layer.x-w/2,y=layer.y-h/2;c.drawImage(layer.img,x,y,w,h);hitBoxes.push({key:'layer:'+layer.id,x:x-12,y:y-12,w:w+24,h:h+24});});
}
function updateLayerList(){const el=document.getElementById('layerList');if(!el)return;if(!graphicLayers.length){el.textContent='Nenhum elemento gráfico adicional.';return;}el.innerHTML='';graphicLayers.forEach(layer=>{const row=document.createElement('div');row.style.cssText='display:flex;align-items:center;gap:8px;margin:5px 0';const label=document.createElement('span');label.style.flex='1';label.textContent=layer.title;const smaller=document.createElement('button');smaller.type='button';smaller.className='btn secondary';smaller.textContent='−';smaller.onclick=()=>{layer.scale=Math.max(.25,layer.scale-.1);render();drawVideoFrame();};const bigger=document.createElement('button');bigger.type='button';bigger.className='btn secondary';bigger.textContent='+';bigger.onclick=()=>{layer.scale=Math.min(2,layer.scale+.1);render();drawVideoFrame();};const remove=document.createElement('button');remove.type='button';remove.className='btn secondary';remove.textContent='Remover';remove.onclick=()=>{graphicLayers=graphicLayers.filter(x=>x.id!==layer.id);updateLayerList();render();drawVideoFrame();};row.append(label,smaller,bigger,remove);el.append(row);});}
function addGraphicLayer(url,title){const im=new Image();im.onload=()=>{graphicLayers.push({id:++layerCounter,img:im,title:title||'Elemento gráfico',x:540,y:960,scale:1});updateLayerList();render();drawVideoFrame();document.getElementById('imageStatus').textContent='Elemento adicionado. Arraste-o diretamente na prévia.';};im.onerror=()=>{document.getElementById('imageStatus').textContent='Não foi possível carregar o elemento gráfico.';};im.src=new URL(url,window.location.href).href;}
function render(){
  hitBoxes=[];ctx.clearRect(0,0,canvas.width,canvas.height);
  let backgroundDrawn=false;
  try{if(bgImage)backgroundDrawn=drawCover(ctx,bgImage)}catch(err){console.error('Falha ao desenhar fundo:',err)}
  if(!backgroundDrawn){const g=ctx.createLinearGradient(0,0,1080,1920);g.addColorStop(0,'#173c79');g.addColorStop(.52,'#315aa0');g.addColorStop(1,'#05070d');ctx.fillStyle=g;ctx.fillRect(0,0,1080,1920)}
  ctx.fillStyle='rgba(0,0,0,.10)';ctx.fillRect(0,0,1080,1920);
  drawImageElement(ctx,houseLogo,'houseLogo','houseSize','houseY',260,220);
  drawTextElement(ctx,'dateText',val('dateText'),120,1920*pct('dateY'),360,48,'left',55,'dateSize');
  drawTextElement(ctx,'city',val('city'),960,1920*pct('cityY'),360,48,'right',55,'citySize');
  if(artistLogo)drawImageElement(ctx,artistLogo,'artistLogo','artistSize','artistY',650,330);else drawTextElement(ctx,'artist',val('artist'),540,1920*pct('artistY'),850,100,'center',110,'artistSize');
  drawTextElement(ctx,'headline',val('headline'),540,1920*pct('phraseY'),900,70,'center',82,'phraseSize');
  drawGraphicLayers(ctx);
}
let imageLoadSequence=0;
function loadImage(file,cb){
  if(!file)return;
  const seq=++imageLoadSequence;
  const status=document.getElementById('imageStatus');
  if(status)status.textContent='Carregando '+file.name+'…';
  if(!/^image\//i.test(file.type)){if(status)status.textContent='Arquivo inválido: selecione JPG, PNG, WEBP ou GIF.';return;}
  const reader=new FileReader();
  reader.onerror=()=>{if(seq===imageLoadSequence&&status)status.textContent='Falha ao ler o arquivo. Tente selecionar a imagem novamente.';};
  reader.onload=()=>{
    if(seq!==imageLoadSequence)return;
    const im=new Image();
    im.onload=()=>{
      if(seq!==imageLoadSequence)return;
      if(!(im.naturalWidth>0&&im.naturalHeight>0)){if(status)status.textContent='A imagem foi lida, mas não tem dimensões válidas.';return;}
      try{
        cb(im);
        render();
        if(typeof drawVideoFrame==='function')drawVideoFrame();
        if(status)status.textContent='Imagem carregada: '+file.name+' ('+im.naturalWidth+'×'+im.naturalHeight+').';
      }catch(err){console.error('Falha ao aplicar imagem:',err);if(status)status.textContent='A imagem carregou, mas ocorreu um erro ao desenhar. Tente outro PNG/JPG.';}
    };
    im.onerror=()=>{if(seq===imageLoadSequence&&status)status.textContent='O navegador não conseguiu decodificar esta imagem. Tente salvar como PNG ou JPG e enviar novamente.';};
    im.src=String(reader.result);
  };
  reader.readAsDataURL(file);
}
function pointerPos(c,e){const r=c.getBoundingClientRect();return{x:(e.clientX-r.left)*c.width/r.width,y:(e.clientY-r.top)*c.height/r.height}}
const positionControl={houseLogo:'houseY',artistLogo:'artistY',artist:'artistY',city:'cityY',dateText:'dateY',headline:'phraseY'};
canvas.addEventListener('pointerdown',e=>{const p=pointerPos(canvas,e);const hit=[...hitBoxes].reverse().find(b=>p.x>=b.x&&p.x<=b.x+b.w&&p.y>=b.y&&p.y<=b.y+b.h);if(!hit)return;if(hit.key.startsWith('layer:')){const layer=graphicLayers.find(x=>x.id===Number(hit.key.split(':')[1]));if(!layer)return;layerDrag={id:layer.id,startX:p.x,startY:p.y,initialX:layer.x,initialY:layer.y};}else{const id=positionControl[hit.key];if(!id)return;dragState={key:hit.key,control:id,startPointerY:p.y,initialValue:Number(val(id))};}canvas.classList.add('dragging');canvas.setPointerCapture(e.pointerId);e.preventDefault()});
canvas.addEventListener('pointermove',e=>{const p=pointerPos(canvas,e);if(layerDrag){const layer=graphicLayers.find(x=>x.id===layerDrag.id);if(layer){layer.x=Math.max(0,Math.min(canvas.width,layerDrag.initialX+p.x-layerDrag.startX));layer.y=Math.max(0,Math.min(canvas.height,layerDrag.initialY+p.y-layerDrag.startY));render();drawVideoFrame();}e.preventDefault();return;}if(!dragState)return;const id=dragState.control,next=Math.max(0,Math.min(100,Math.round(dragState.initialValue+(p.y-dragState.startPointerY)/canvas.height*100)));document.getElementById(id).value=next;const label=document.getElementById(id+'Label');if(label)label.textContent=next+'%';render();if(typeof drawVideoFrame==='function')drawVideoFrame();e.preventDefault()});
['pointerup','pointercancel','lostpointercapture'].forEach(ev=>canvas.addEventListener(ev,()=>{dragState=null;layerDrag=null;canvas.classList.remove('dragging')}));
document.getElementById('clearLayers').addEventListener('click',()=>{graphicLayers=[];updateLayerList();render();drawVideoFrame();});
document.getElementById('bgFile').addEventListener('change',e=>{
  const file=e.target.files&&e.target.files[0]; if(!file)return;
  loadImage(file,im=>{bgImage=im;document.getElementById('bgUrl').value=file.name;});
});
document.getElementById('removeBg').addEventListener('click',()=>{bgImage=null;document.getElementById('bgUrl').value='';document.getElementById('bgFile').value='';render();if(typeof drawVideoFrame==='function')drawVideoFrame();document.getElementById('imageStatus').textContent='Fundo removido. A arte está usando o fundo alternativo.';});
document.getElementById('houseLogoFile').addEventListener('change',e=>loadImage(e.target.files&&e.target.files[0],im=>{houseLogo=im;render();if(typeof drawVideoFrame==='function')drawVideoFrame()}));
document.getElementById('artistLogoFile').addEventListener('change',e=>loadImage(e.target.files&&e.target.files[0],im=>{artistLogo=im;render();if(typeof drawVideoFrame==='function')drawVideoFrame()}));
['artist','city','dateText','headline','accent','houseSize','houseY','artistSize','artistY','citySize','cityY','dateSize','dateY','phraseSize','phraseY'].forEach(id=>document.getElementById(id).addEventListener('input',()=>{const label=document.getElementById(id+'Label');if(label)label.textContent=val(id)+'%';render();if(typeof drawVideoFrame==='function')drawVideoFrame()}));document.getElementById('downloadPng').addEventListener('click',()=>{const a=document.createElement('a');a.download='metadash-arte.png';a.href=canvas.toDataURL('image/png');a.click()});
function loadImageFromUrl(url,target){
  const raw=(url||'').trim();
  if(!raw)return;
  // Aceita caminhos relativos do próprio MetaDash e URLs absolutas.
  const resolved=new URL(raw,window.location.href).href;
  const im=new Image();
  const imageStatus=document.getElementById('imageStatus');
  if(imageStatus)imageStatus.textContent='Carregando imagem do caminho informado…';
  im.onload=()=>{
    if(!(im.naturalWidth>0&&im.naturalHeight>0)){if(imageStatus)imageStatus.textContent='A imagem carregou sem dimensões válidas.';return;}
    if(target==='house')houseLogo=im;else if(target==='artist')artistLogo=im;else bgImage=im;render();if(typeof drawVideoFrame==='function')drawVideoFrame();
    if(imageStatus)imageStatus.textContent='Imagem carregada: '+im.naturalWidth+'×'+im.naturalHeight+'.';
  };
  im.onerror=()=>{if(imageStatus)imageStatus.textContent='Não foi possível carregar o caminho. Use o seletor de arquivo para enviar a imagem local.';};
  im.src=resolved;
}
const bgUrlInput=document.getElementById('bgUrl');
bgUrlInput.addEventListener('change',()=>loadImageFromUrl(bgUrlInput.value));
bgUrlInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadImageFromUrl(bgUrlInput.value)}});
document.querySelectorAll('.use-asset').forEach(b=>b.addEventListener('click',()=>{
  const cat=b.dataset.category,url=b.dataset.url,title=b.dataset.title||'Material';
  if(b.dataset.kind==='video'){setVideoUrl(new URL(url,window.location.href).href);document.querySelector('[data-tab="video"]').click();return;}
  if(cat==='logo_casa'){loadImageFromUrl(url,'house');}
  else if(cat==='logo_artista'){loadImageFromUrl(url,'artist');}
  else if(cat==='elemento'){addGraphicLayer(url,title);}
  else {document.getElementById('bgUrl').value=url;loadImageFromUrl(url,'background');}
}));
document.querySelectorAll('.add-layer').forEach(b=>b.addEventListener('click',()=>{if(b.dataset.kind==='image')addGraphicLayer(b.dataset.url,b.dataset.title);else{document.getElementById('videoStatus').textContent='Vídeos podem ser usados como fundo nesta versão; camadas de vídeo independentes serão adicionadas numa etapa posterior.';}}));
document.querySelectorAll('.lib-filter').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.lib-filter').forEach(x=>x.classList.toggle('active',x===b));document.querySelectorAll('.asset').forEach(card=>{card.style.display=(b.dataset.filter==='all'||card.dataset.category===b.dataset.filter)?'':'none';});}));
document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('active',x===b));document.getElementById('imagePanel').classList.toggle('hidden',b.dataset.tab!=='image');document.getElementById('videoPanel').classList.toggle('hidden',b.dataset.tab!=='video')}));
// Video editor: background video plus the same independent elements as the image model.
const videoSource=document.getElementById('videoSource'),videoCanvas=document.getElementById('videoCanvas'),vctx=videoCanvas.getContext('2d');let videoDragState=null,videoFrame=0,videoRecorder=null,videoChunks=[],videoHitBoxes=[];
let currentVideoObjectUrl=null;
function setVideoUrl(url){
  if(currentVideoObjectUrl && currentVideoObjectUrl!==url){URL.revokeObjectURL(currentVideoObjectUrl);}
  currentVideoObjectUrl=(typeof url==='string' && url.startsWith('blob:'))?url:null;
  videoSource.pause();
  videoSource.muted=true;
  videoSource.playsInline=true;
  videoSource.loop=true;
  videoSource.src=url;
  videoSource.load();
  videoAutoplayAttempted=false;
  document.getElementById('videoStatus').textContent='Carregando vídeo…';
  drawVideoFrame();
}
let videoBytesOrigem=0,videoArquivoOrigem=null;
document.getElementById('videoFile').addEventListener('change',e=>{
  const file=e.target.files&&e.target.files[0]; if(!file)return;
  videoBytesOrigem=file.size||0;videoArquivoOrigem=file;
  setVideoUrl(URL.createObjectURL(file));
});
function bindImageUpload(inputId, assign, label){
  const el=document.getElementById(inputId); if(!el)return;
  el.addEventListener('change',e=>{
    const file=e.target.files&&e.target.files[0]; if(!file)return;
    loadImage(file,im=>{assign(im);render();drawVideoFrame();const st=document.getElementById('videoStatus');if(st)st.textContent=label+' carregada e aplicada às prévias.';});
  });
}
document.getElementById('removeVideoBg').addEventListener('click',()=>{videoSource.pause();videoSource.removeAttribute('src');videoSource.load();document.getElementById('videoFile').value='';bgImage=null;document.getElementById('bgUrl').value='';document.getElementById('bgFile').value='';render();drawVideoFrame();document.getElementById('videoStatus').textContent='Vídeo e fundo removidos. Logos e textos foram mantidos.';});
bindImageUpload('videoBgImageFile',im=>{bgImage=im;document.getElementById('bgUrl').value='Imagem enviada pela aba Vídeo';},'Imagem de fundo');
bindImageUpload('videoHouseLogoFile',im=>{houseLogo=im;},'Logo da casa');
bindImageUpload('videoArtistLogoFile',im=>{artistLogo=im;},'Logo do artista');
// Durante a exportação as logos e os textos não mudam. Desenhá-los a cada
// quadro custava mais que o próprio vídeo e fazia a composição perder quadros
// — e cada quadro perdido estica o arquivo final (16 quadros a 24fps = 0,67s).
// Aqui eles são rasterizados uma vez e o quadro vira 2 drawImage.
let overlayCache=null;
function construirOverlayCache(){
  const c=document.createElement('canvas');
  c.width=videoCanvas.width;c.height=videoCanvas.height;
  const o=c.getContext('2d');
  o.fillStyle='rgba(0,0,0,.10)';o.fillRect(0,0,c.width,c.height);
  const oldBoxes=hitBoxes;hitBoxes=[];
  drawImageElement(o,houseLogo,'houseLogo','houseSize','houseY',260,220);
  drawTextElement(o,'dateText',val('dateText'),120,1920*pct('dateY'),360,48,'left',55,'dateSize');
  drawTextElement(o,'city',val('city'),960,1920*pct('cityY'),360,48,'right',55,'citySize');
  if(artistLogo)drawImageElement(o,artistLogo,'artistLogo','artistSize','artistY',650,330);
  else drawTextElement(o,'artist',val('artist'),540,1920*pct('artistY'),850,100,'center',110,'artistSize');
  drawTextElement(o,'headline',val('headline'),540,1920*pct('phraseY'),900,70,'center',82,'phraseSize');
  drawGraphicLayers(o);
  hitBoxes=oldBoxes;
  overlayCache=c;
}
function drawVideoFrame(){
  // caminho rápido da exportação
  if(overlayCache&&videoSource.readyState>=2&&videoSource.videoWidth){
    const sc=Math.max(videoCanvas.width/videoSource.videoWidth,videoCanvas.height/videoSource.videoHeight),
          w=videoSource.videoWidth*sc,h=videoSource.videoHeight*sc;
    vctx.drawImage(videoSource,(videoCanvas.width-w)/2,(videoCanvas.height-h)/2,w,h);
    vctx.drawImage(overlayCache,0,0);
    return;
  }
  vctx.clearRect(0,0,videoCanvas.width,videoCanvas.height);
  if(videoSource.readyState>=2&&videoSource.videoWidth){
    const sc=Math.max(videoCanvas.width/videoSource.videoWidth,videoCanvas.height/videoSource.videoHeight),w=videoSource.videoWidth*sc,h=videoSource.videoHeight*sc;
    vctx.drawImage(videoSource,(videoCanvas.width-w)/2,(videoCanvas.height-h)/2,w,h);
  } else if(bgImage){
    drawCover(vctx,bgImage);
  } else {
    vctx.fillStyle='#080b14';vctx.fillRect(0,0,videoCanvas.width,videoCanvas.height);vctx.fillStyle='#8ea0c3';vctx.font='28px Arial';vctx.textAlign='center';vctx.fillText('Envie um vídeo ou uma imagem de fundo',540,960);
  }
  vctx.fillStyle='rgba(0,0,0,.10)';vctx.fillRect(0,0,videoCanvas.width,videoCanvas.height);
  const oldBoxes=hitBoxes;hitBoxes=[];
  drawImageElement(vctx,houseLogo,'houseLogo','houseSize','houseY',260,220);
  drawTextElement(vctx,'dateText',val('dateText'),120,1920*pct('dateY'),360,48,'left',55,'dateSize');
  drawTextElement(vctx,'city',val('city'),960,1920*pct('cityY'),360,48,'right',55,'citySize');
  if(artistLogo)drawImageElement(vctx,artistLogo,'artistLogo','artistSize','artistY',650,330);else drawTextElement(vctx,'artist',val('artist'),540,1920*pct('artistY'),850,100,'center',110,'artistSize');
  drawTextElement(vctx,'headline',val('headline'),540,1920*pct('phraseY'),900,70,'center',82,'phraseSize');
  drawGraphicLayers(vctx);
  videoHitBoxes=hitBoxes;hitBoxes=oldBoxes;
}
function videoLoop(){drawVideoFrame();if(!videoSource.paused&&!videoSource.ended)videoFrame=requestAnimationFrame(videoLoop)}
videoCanvas.addEventListener('pointerdown',e=>{const p=pointerPos(videoCanvas,e),hit=[...videoHitBoxes].reverse().find(b=>p.x>=b.x&&p.x<=b.x+b.w&&p.y>=b.y&&p.y<=b.y+b.h);if(!hit)return;if(hit.key.startsWith('layer:')){const layer=graphicLayers.find(x=>x.id===Number(hit.key.split(':')[1]));if(!layer)return;layerDrag={id:layer.id,startX:p.x,startY:p.y,initialX:layer.x,initialY:layer.y,video:true};}else{const id=positionControl[hit.key];if(!id)return;videoDragState={key:hit.key,control:id,startPointerY:p.y,initialValue:Number(val(id))};}videoCanvas.classList.add('dragging');videoCanvas.setPointerCapture(e.pointerId);e.preventDefault()});
videoCanvas.addEventListener('pointermove',e=>{const p=pointerPos(videoCanvas,e);if(layerDrag&&layerDrag.video){const layer=graphicLayers.find(x=>x.id===layerDrag.id);if(layer){layer.x=Math.max(0,Math.min(videoCanvas.width,layerDrag.initialX+p.x-layerDrag.startX));layer.y=Math.max(0,Math.min(videoCanvas.height,layerDrag.initialY+p.y-layerDrag.startY));render();drawVideoFrame();}e.preventDefault();return;}if(!videoDragState)return;const id=videoDragState.control,next=Math.max(0,Math.min(100,Math.round(videoDragState.initialValue+(p.y-videoDragState.startPointerY)/videoCanvas.height*100)));document.getElementById(id).value=next;const label=document.getElementById(id+'Label');if(label)label.textContent=next+'%';render();drawVideoFrame();e.preventDefault()});
['pointerup','pointercancel','lostpointercapture'].forEach(ev=>videoCanvas.addEventListener(ev,()=>{videoDragState=null;if(layerDrag&&layerDrag.video)layerDrag=null;videoCanvas.classList.remove('dragging')}));
// Removido listener de videoY inexistente: ele interrompia todo o JavaScript antes da prévia inicializar.
document.getElementById('videoPlay').addEventListener('click',async()=>{if(!videoSource.src){document.getElementById('videoStatus').textContent='Selecione um vídeo primeiro.';return}try{await videoSource.play();cancelAnimationFrame(videoFrame);videoLoop()}catch(e){document.getElementById('videoStatus').textContent='O navegador bloqueou a reprodução. Tente novamente.'}});
let videoExporting=false,exportAudioContext=null,exportAudioSource=null,exportAudioDestination=null,exportAudioStream=null,exportSilentGain=null;
function waitForVideoFrame(timeoutMs){
  if(videoSource.readyState>=2&&videoSource.videoWidth>0)return Promise.resolve();
  return new Promise((resolve,reject)=>{
    let done=false;
    const finish=(err)=>{if(done)return;done=true;clearTimeout(timer);videoSource.removeEventListener('loadeddata',onReady);videoSource.removeEventListener('canplay',onReady);videoSource.removeEventListener('error',onError);err?reject(err):resolve()};
    const onReady=()=>finish();
    const onError=()=>finish(new Error('O navegador não conseguiu carregar o vídeo.'));
    const timer=setTimeout(()=>finish(new Error('O vídeo demorou demais para carregar. Tente selecionar o arquivo novamente.')),timeoutMs||15000);
    videoSource.addEventListener('loadeddata',onReady,{once:true});videoSource.addEventListener('canplay',onReady,{once:true});videoSource.addEventListener('error',onError,{once:true});
  });
}
async function getVideoAudioTrack(){
  const AudioContextClass=window.AudioContext||window.webkitAudioContext;
  if(!AudioContextClass)throw new Error('Este navegador não permite incluir o áudio na exportação. Use o Safari atualizado.');
  if(!exportAudioContext){
    exportAudioContext=new AudioContextClass();
    exportAudioSource=exportAudioContext.createMediaElementSource(videoSource);
    exportAudioDestination=exportAudioContext.createMediaStreamDestination();
    exportAudioStream=exportAudioDestination.stream;
    exportSilentGain=exportAudioContext.createGain();
    exportSilentGain.gain.value=0;
    exportAudioSource.connect(exportAudioDestination);
    exportAudioSource.connect(exportSilentGain);
    exportSilentGain.connect(exportAudioContext.destination);
  }
  if(exportAudioContext.state==='suspended')await exportAudioContext.resume();
  const track=exportAudioStream.getAudioTracks()[0];
  if(!track)throw new Error('Não foi possível capturar o áudio do vídeo.');
  return track;
}
function seekVideoToStart(){
  if(videoSource.currentTime<0.05)return Promise.resolve();
  return new Promise((resolve,reject)=>{
    const timer=setTimeout(()=>{videoSource.removeEventListener('seeked',onSeeked);reject(new Error('Não foi possível voltar o vídeo ao início.'))},5000);
    const onSeeked=()=>{clearTimeout(timer);resolve()};
    videoSource.addEventListener('seeked',onSeeked,{once:true});
    try{videoSource.currentTime=0}catch(e){clearTimeout(timer);videoSource.removeEventListener('seeked',onSeeked);reject(e)}
  });
}
function syncExportFrames(fps){
  // ATENÇÃO: a versão anterior usava videoSource.requestVideoFrameCallback com
  // captureStream(0) + requestFrame(). O elemento <video> de origem fica
  // praticamente invisível (2x2 px, opacity .02) e o navegador para de
  // "apresentar" quadros de um vídeo que ninguém vê — a callback simplesmente
  // deixa de disparar. O resultado era um MP4 com 28 quadros em 23 segundos.
  // Aqui o desenho é pilotado por relógio próprio: drawImage() lê o quadro
  // decodificado do vídeo mesmo sem apresentação, então nunca para.
  let stopped=false,timer=null,intervalId=null,desenhados=0,escondeu=false;
  const intervalo=1000/fps;
  let proximo=performance.now();
  const desenhar=()=>{
    if(stopped)return;
    const agora=performance.now();
    if(agora<proximo)return;
    try{drawVideoFrame();desenhados++;}catch(_){}
    // não acumula atraso: se a máquina engasgou, pula para o próximo slot
    proximo+=intervalo;
    if(proximo<agora-intervalo)proximo=agora+intervalo;
  };
  const tick=()=>{
    if(stopped)return;
    desenhar();
    timer=requestAnimationFrame(tick);
  };
  // Duas fontes de relógio. requestAnimationFrame é a boa, mas para quando a
  // aba vai para segundo plano; o setInterval segue (estrangulado, porém vivo)
  // e evita perder tudo. O controle por 'proximo' impede desenho em dobro.
  tick();
  intervalId=setInterval(desenhar,intervalo);
  const aoEsconder=function(){ if(document.hidden)escondeu=true; };
  document.addEventListener('visibilitychange',aoEsconder);
  return {
    stop:function(){
      stopped=true;
      if(timer)cancelAnimationFrame(timer);
      if(intervalId)clearInterval(intervalId);
      document.removeEventListener('visibilitychange',aoEsconder);
    },
    contador:function(){return desenhados;},
    saiuDeFoco:function(){return escondeu;}
  };
}
// Aquece o decodificador e mede a taxa de quadros real do vídeo de origem.
// Sem isso, o gravador começa antes de o vídeo render o primeiro quadro: o
// começo sai como imagem congelada e o arquivo fica mais longo que o original.
// ── MOTOR DETERMINÍSTICO (WebCodecs) ───────────────────────────────
// O MediaRecorder grava em tempo real: a duração do arquivo é o tempo de
// relógio, então todo quadro perdido alonga o resultado. Aqui cada quadro
// recebe um timestamp calculado (i/fps), e o arquivo sai com a duração exata
// do original, com ou sem máquina rápida.
function infoAudioFalhou(r){return r&&r.audioOk===false;}
function webCodecsDisponivel(){
  return typeof window.VideoEncoder==='function'
      && typeof window.VideoFrame==='function'
      && typeof window.Mp4Muxer==='object';
}
// O evento 'seeked' chega ANTES de o quadro final estar pintado: desenhar
// nesse instante captura uma prévia borrada, e às vezes de outro trecho.
// Por isso esperamos também a apresentação real do quadro.
function irParaTempo(t){
  return new Promise(function(resolve){
    let pronto=false;
    const fim=function(){if(pronto)return;pronto=true;resolve();};
    const prazo=setTimeout(fim,3000);
    const aposSeek=function(){
      if(typeof videoSource.requestVideoFrameCallback==='function'){
        videoSource.requestVideoFrameCallback(function(){clearTimeout(prazo);fim();});
        // alguns navegadores não apresentam quadro com o vídeo pausado
        setTimeout(function(){if(!pronto){clearTimeout(prazo);fim();}},120);
      }else{
        requestAnimationFrame(function(){requestAnimationFrame(function(){clearTimeout(prazo);fim();});});
      }
    };
    if(Math.abs(videoSource.currentTime-t)<0.0005){aposSeek();return;}
    videoSource.addEventListener('seeked',aposSeek,{once:true});
    try{videoSource.currentTime=t;}catch(e){clearTimeout(prazo);fim();}
  });
}
// O Safari devolve um decoderConfig.description inválido para AAC (bug WebKit
// 302253): manda um bloco esds onde o MP4 espera o AudioSpecificConfig cru. O
// muxer grava do jeito que veio e a trilha fica indecodificável — o vídeo sai
// mudo. Montamos o ASC nós mesmos, que é determinístico.
function montarAudioSpecificConfig(sampleRate,canais){
  const tabela=[96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
  let idx=tabela.indexOf(sampleRate);
  if(idx<0)idx=4; // 44100 como aproximação segura
  const objeto=2; // AAC LC
  const b0=(objeto<<3)|(idx>>1);
  const b1=((idx&1)<<7)|((canais&0x0f)<<3);
  return new Uint8Array([b0,b1]);
}
// Alguns codificadores entregam o quadro com cabeçalho ADTS, que não pode
// entrar num MP4.
function removerAdts(bytes){
  if(bytes.length>7&&bytes[0]===0xFF&&(bytes[1]&0xF6)===0xF0){
    const tamCabecalho=(bytes[1]&0x01)?7:9;
    if(bytes.length>tamCabecalho)return bytes.subarray(tamCabecalho);
  }
  return bytes;
}
async function codificarAudio(muxer,arquivo,taxaAudio,info){
  let buf;
  try{
    const dados=await arquivo.arrayBuffer();
    const ac=new (window.AudioContext||window.webkitAudioContext)();
    buf=await ac.decodeAudioData(dados);
    try{ac.close();}catch(e){}
  }catch(e){return 0;}
  if(!buf||!buf.length)return 0;

  const canais=info.numberOfChannels, taxa=info.sampleRate;
  const asc=montarAudioSpecificConfig(taxa,canais);
  const metaFixo={decoderConfig:{codec:'mp4a.40.2',sampleRate:taxa,numberOfChannels:canais,description:asc}};
  let erro=false,blocos=0;

  const enc=new AudioEncoder({
    output:function(chunk,meta){
      try{
        const bruto=new Uint8Array(chunk.byteLength);
        chunk.copyTo(bruto);
        const limpo=removerAdts(bruto);
        const final=(limpo===bruto)?chunk:new EncodedAudioChunk({
          type:chunk.type,timestamp:chunk.timestamp,
          duration:chunk.duration||Math.round(1024/taxa*1e6),
          data:limpo
        });
        muxer.addAudioChunk(final,metaFixo);
        blocos++;
      }catch(e){erro=true;}
    },
    error:function(){erro=true;}
  });
  try{
    enc.configure({codec:'mp4a.40.2',sampleRate:taxa,numberOfChannels:canais,bitrate:taxaAudio});
  }catch(e){return 0;}

  const BLOCO=1024;
  const entrada=[];
  for(let c=0;c<canais;c++)entrada.push(buf.getChannelData(Math.min(c,buf.numberOfChannels-1)));
  for(let pos=0;pos<buf.length;pos+=BLOCO){
    if(erro)break;
    const n=Math.min(BLOCO,buf.length-pos);
    const plano=new Float32Array(n*canais);
    for(let c=0;c<canais;c++)plano.set(entrada[c].subarray(pos,pos+n),c*n);
    const ad=new AudioData({
      format:'f32-planar',sampleRate:taxa,numberOfFrames:n,numberOfChannels:canais,
      timestamp:Math.round(pos/taxa*1e6),data:plano
    });
    try{enc.encode(ad);}catch(e){erro=true;}
    ad.close();
    if(enc.encodeQueueSize>24)await new Promise(function(r){setTimeout(r,0);});
  }
  try{await enc.flush();}catch(e){erro=true;}
  try{enc.close();}catch(e){}
  return erro?0:blocos;
}
async function exportarDeterministico(opcoes){
  const fps=opcoes.fps,duracao=opcoes.duracao,taxaVideo=opcoes.taxaVideo,
        arquivo=opcoes.arquivo,status=opcoes.status,botao=opcoes.botao;
  const L=videoCanvas.width,A=videoCanvas.height;
  const totalQuadros=Math.max(1,Math.round(duracao*fps));

  // Sem AAC não seguimos por aqui: entregar vídeo mudo é pior do que a
  // gravação em tempo real, que ao menos carrega o áudio.
  let infoAudio=null;
  if(typeof window.AudioEncoder==='function'&&arquivo){
    try{
      const ac=new (window.AudioContext||window.webkitAudioContext)();
      const b=await ac.decodeAudioData((await arquivo.arrayBuffer()).slice(0));
      const cfg={codec:'mp4a.40.2',sampleRate:b.sampleRate,
                 numberOfChannels:Math.min(2,b.numberOfChannels)||1,bitrate:256000};
      const sup=await AudioEncoder.isConfigSupported(cfg);
      if(sup&&sup.supported)infoAudio={sampleRate:cfg.sampleRate,numberOfChannels:cfg.numberOfChannels};
      try{ac.close();}catch(e){}
    }catch(e){infoAudio=null;}
  }
  if(!infoAudio)throw new Error('WEBCODECS_SEM_AUDIO');

  const muxer=new Mp4Muxer.Muxer({
    target:new Mp4Muxer.ArrayBufferTarget(),
    video:{codec:'avc',width:L,height:A},
    audio:infoAudio?{codec:'aac',sampleRate:infoAudio.sampleRate,numberOfChannels:infoAudio.numberOfChannels}:undefined,
    fastStart:'in-memory'
  });

  let erroEnc=null;
  const enc=new VideoEncoder({
    output:function(chunk,meta){try{muxer.addVideoChunk(chunk,meta);}catch(e){erroEnc=e;}},
    error:function(e){erroEnc=e;}
  });
  // bitrateMode 'constant' é obrigatório aqui: no modo variável (padrão) o
  // codificador por software despreza a taxa pedida e entrega uma fração dela,
  // destruindo o fundo. Perfil High (640028) primeiro, que comprime melhor.
  const cfg={codec:'avc1.640028',width:L,height:A,bitrate:taxaVideo,framerate:fps,
             bitrateMode:'constant',latencyMode:'quality',avc:{format:'avc'}};
  let ok=false;
  for(const c of ['avc1.640028','avc1.4D4028','avc1.42E01E']){
    cfg.codec=c;
    try{
      const s=await VideoEncoder.isConfigSupported(cfg);
      if(s&&s.supported){enc.configure(cfg);ok=true;break;}
    }catch(e){}
  }
  if(!ok)throw new Error('WEBCODECS_INDISPONIVEL');

  // Captura durante a reprodução, carimbando cada quadro com o tempo de MÍDIA
  // (mediaTime), não com o relógio do sistema. Assim a duração final é a do
  // vídeo, mesmo que a máquina engasgue. Buracos são preenchidos repetindo o
  // último quadro, mantendo taxa constante.
  const duracaoQuadro=Math.round(1e6/fps);
  await irParaTempo(0);
  videoSource.muted=true;
  let ultimoIndice=-1,enviados=0;

  await new Promise(function(resolve,reject){
    let encerrado=false;
    const finalizar=function(){if(encerrado)return;encerrado=true;resolve();};

    const enviarAte=function(indice){
      if(indice<=ultimoIndice)return;
      // repete o último quadro nos índices que o decodificador pulou
      for(let k=ultimoIndice+1;k<=indice;k++){
        if(k>=totalQuadros)break;
        const vf=new VideoFrame(videoCanvas,{timestamp:Math.round(k*1e6/fps),duration:duracaoQuadro});
        try{enc.encode(vf,{keyFrame:k%(fps*2)===0});enviados++;}catch(e){erroEnc=e;}
        vf.close();
      }
      ultimoIndice=Math.min(indice,totalQuadros-1);
    };

    const aoQuadro=function(_,meta){
      if(encerrado)return;
      if(erroEnc){finalizar();return;}
      const tm=(meta&&typeof meta.mediaTime==='number')?meta.mediaTime:videoSource.currentTime;
      drawVideoFrame();
      enviarAte(Math.min(totalQuadros-1,Math.round(tm*fps)));
      if(ultimoIndice>=totalQuadros-1){finalizar();return;}
      if(enviados%12===0){
        const pc=Math.round(ultimoIndice/totalQuadros*100);
        status.textContent='Renderizando — '+pc+'% ('+ultimoIndice+'/'+totalQuadros+')';
        if(botao)botao.textContent='Renderizando '+pc+'%';
      }
      videoSource.requestVideoFrameCallback(aoQuadro);
    };

    const porTempo=function(){
      if(encerrado)return;
      drawVideoFrame();
      enviarAte(Math.min(totalQuadros-1,Math.round(videoSource.currentTime*fps)));
      if(ultimoIndice>=totalQuadros-1||videoSource.ended){finalizar();return;}
      requestAnimationFrame(porTempo);
    };

    videoSource.addEventListener('ended',function(){enviarAte(totalQuadros-1);finalizar();},{once:true});
    // rede de segurança: nunca travar além do dobro da duração
    setTimeout(function(){enviarAte(totalQuadros-1);finalizar();},duracao*1000*2+8000);

    videoSource.play().then(function(){
      if(typeof videoSource.requestVideoFrameCallback==='function')videoSource.requestVideoFrameCallback(aoQuadro);
      else porTempo();
    }).catch(reject);
  });
  try{videoSource.pause();}catch(e){}

  await enc.flush();
  enc.close();
  if(erroEnc)throw erroEnc;

  status.textContent='Codificando o áudio…';
  const blocosAudio=await codificarAudio(muxer,arquivo,256000,infoAudio);
  // Sem blocos de áudio o arquivo sairia mudo. Melhor desistir e deixar a
  // gravação em tempo real assumir do que entregar vídeo sem som.
  if(!blocosAudio)throw new Error('WEBCODECS_SEM_AUDIO');
  const audioOk=true;
  muxer.finalize();
  return {blob:new Blob([muxer.target.buffer],{type:'video/mp4'}),quadros:totalQuadros,audioOk:audioOk};
}

// Resolve quando o vídeo realmente avançou (primeiro quadro apresentado).
function esperarVideoAndar(limite){
  return new Promise(function(resolve){
    const inicio=videoSource.currentTime;
    let pronto=false;
    const terminar=function(){if(pronto)return;pronto=true;resolve();};
    const prazo=setTimeout(terminar,limite||1500);
    if(typeof videoSource.requestVideoFrameCallback==='function'){
      videoSource.requestVideoFrameCallback(function(){clearTimeout(prazo);terminar();});
      return;
    }
    const olhar=function(){
      if(pronto)return;
      if(videoSource.currentTime>inicio){clearTimeout(prazo);terminar();return;}
      requestAnimationFrame(olhar);
    };
    olhar();
  });
}

async function aquecerEMedirFps(){
  const PADRAO=30;
  let fps=PADRAO;
  try{
    videoSource.muted=true;
    await videoSource.play();
    if(typeof videoSource.requestVideoFrameCallback==='function'){
      fps=await new Promise(function(resolve){
        const marcas=[];
        const encerrar=setTimeout(function(){resolve(calcular(marcas));},700);
        const passo=function(_,meta){
          marcas.push(meta&&typeof meta.mediaTime==='number'?meta.mediaTime:videoSource.currentTime);
          if(marcas.length>=12){clearTimeout(encerrar);resolve(calcular(marcas));return;}
          videoSource.requestVideoFrameCallback(passo);
        };
        videoSource.requestVideoFrameCallback(passo);
        function calcular(m){
          if(m.length<4)return PADRAO;
          const difs=[];
          for(let i=1;i<m.length;i++){const d=m[i]-m[i-1];if(d>0.001&&d<0.5)difs.push(d);}
          if(difs.length<3)return PADRAO;
          difs.sort(function(x,y){return x-y;});
          const mediana=difs[Math.floor(difs.length/2)];
          const bruto=1/mediana;
          // encaixa na taxa padrão mais próxima
          const comuns=[23.976,24,25,29.97,30,50,59.94,60];
          let melhor=PADRAO,dist=Infinity;
          for(let i=0;i<comuns.length;i++){
            const dd=Math.abs(comuns[i]-bruto);
            if(dd<dist){dist=dd;melhor=comuns[i];}
          }
          return dist<=2.5?melhor:PADRAO;
        }
      });
    }else{
      await new Promise(function(r){setTimeout(r,400);});
    }
  }catch(e){}
  try{videoSource.pause();}catch(e){}
  return Math.max(15,Math.min(60,Math.round(fps)));
}

// Formatos em ordem de preferência. MP4 com AAC primeiro: MP4 com Opus é
// aceito por alguns navegadores mas quebra em vários players e no
// transcodificador do Instagram. Sem MP4, grava WebM em vez de recusar —
// era o que impedia Chrome e Firefox de exportar.
function escolherFormato(){
  const opcoes=[
    {mime:'video/mp4;codecs=avc1.42E01E,mp4a.40.2', ext:'mp4', nota:''},
    {mime:'video/mp4;codecs=avc1,mp4a.40.2',        ext:'mp4', nota:''},
    {mime:'video/mp4;codecs=avc1.42E01E',           ext:'mp4', nota:''},
    {mime:'video/mp4',                              ext:'mp4', nota:''},
    {mime:'video/webm;codecs=vp9,opus',             ext:'webm',nota:'Seu navegador não grava MP4; o arquivo saiu em WebM. O Instagram aceita, mas para enviar como MP4 use o Chrome ou o Safari atualizados.'},
    {mime:'video/webm;codecs=vp8,opus',             ext:'webm',nota:'Seu navegador não grava MP4; o arquivo saiu em WebM. O Instagram aceita, mas para enviar como MP4 use o Chrome ou o Safari atualizados.'},
    {mime:'video/webm',                             ext:'webm',nota:'Seu navegador não grava MP4; o arquivo saiu em WebM.'}
  ];
  for(let i=0;i<opcoes.length;i++){
    if(window.MediaRecorder&&MediaRecorder.isTypeSupported(opcoes[i].mime))return opcoes[i];
  }
  return null;
}
document.getElementById('downloadVideo').addEventListener('click',async()=>{
  const button=document.getElementById('downloadVideo'),status=document.getElementById('videoStatus');
  if(videoExporting)return;
  if(!videoSource.src){status.textContent='Selecione um vídeo primeiro.';return;}
  if(!window.MediaRecorder||!videoCanvas.captureStream){status.textContent='Este navegador não oferece gravação de vídeo compatível. Abra o Estúdio no Safari atualizado.';return;}
  const formato=escolherFormato();
  if(!formato){status.textContent='Este navegador não oferece gravação de vídeo. Use Chrome, Safari ou Firefox atualizados.';return;}
  const mime=formato.mime;
  let FPS=30;

  videoExporting=true;button.disabled=true;button.textContent='Preparando vídeo…';
  let canvasStream=null,recordStream=null,stopTimer=null,stopWatchdog=null,completionTimer=null,endedHandler=null,stopFrameSync=null;
  const recorderRef=()=>videoRecorder;
  try{
    await waitForVideoFrame(15000);
    const duration=Number(videoSource.duration);
    if(!Number.isFinite(duration)||duration<=0)throw new Error('A duração total do vídeo não pôde ser lida. Tente reenviar o arquivo.');
    videoChunks=[];
    videoSource.pause();videoSource.loop=false;videoSource.playbackRate=1;
    // Aquece o decodificador e descobre a taxa nativa. Gravar um vídeo de 25fps
    // a 30fps produz repetição irregular de quadro — o movimento fica serrilhado.
    button.textContent='Analisando vídeo…';
    FPS=await aquecerEMedirFps();
    videoSource.muted=false;videoSource.volume=1;
    await seekVideoToStart();
    const audioTrack=await getVideoAudioTrack();
    // Taxa fixa: o navegador amostra o canvas a 30fps sozinho, gerando um
    // arquivo CFR. Em VFR o Instagram reprocessa e o vídeo engasga.
    canvasStream=videoCanvas.captureStream(FPS);
    const canvasTrack=canvasStream.getVideoTracks()[0];
    if(!canvasTrack)throw new Error('Não foi possível criar a faixa de vídeo para exportação.');
    recordStream=new MediaStream([...canvasStream.getVideoTracks(),audioTrack]);
    // Taxa de bits espelhando o arquivo de origem. Fixar 8 Mbps degradava o
    // fundo de vídeos mais pesados: um original de 16 Mbps voltava com metade
    // da informação. Margem de 15% porque recompor o quadro com logos e texto
    // cria detalhe novo que o codificador precisa descrever.
    let taxaVideo=8000000;
    if(videoBytesOrigem>0&&duration>0){
      const taxaOrigem=(videoBytesOrigem*8)/duration;
      taxaVideo=Math.round(Math.min(24000000,Math.max(8000000,taxaOrigem*1.15)));
    }
    videoRecorder=new MediaRecorder(recordStream,{mimeType:mime,videoBitsPerSecond:taxaVideo,audioBitsPerSecond:256000});
    const recorder=videoRecorder;
    const completed=new Promise((resolve,reject)=>{
      recorder.ondataavailable=e=>{if(e.data&&e.data.size)videoChunks.push(e.data);};
      recorder.onerror=e=>reject(e.error||new Error('Falha durante a codificação do MP4.'));
      recorder.onstop=()=>resolve();
    });
    cancelAnimationFrame(videoFrame);
    construirOverlayCache();

    // Caminho preferido: renderização quadro a quadro com duração exata.
    if(webCodecsDisponivel()){
      try{
        const r=await exportarDeterministico({fps:FPS,duracao:duration,taxaVideo:taxaVideo,
                                              arquivo:videoArquivoOrigem,status:status,botao:button});
        const url=URL.createObjectURL(r.blob),a2=document.createElement('a');
        a2.href=url;a2.download='metadash-video.mp4';document.body.appendChild(a2);a2.click();a2.remove();
        setTimeout(function(){URL.revokeObjectURL(url);},60000);
        status.textContent='MP4 exportado quadro a quadro: '+r.quadros+' quadros a '+FPS+'fps, '
                         +(taxaVideo/1000000).toFixed(1)+' Mbps, duração idêntica ao original'
                         +' e áudio AAC.';
        return;
      }catch(e){
        if(!e||(e.message!=='WEBCODECS_INDISPONIVEL'&&e.message!=='WEBCODECS_SEM_AUDIO')){
          status.textContent='Renderização quadro a quadro falhou ('+((e&&e.message)||'erro')+'); gravando em tempo real…';
        }
        try{videoSource.pause();videoSource.currentTime=0;}catch(_){}
      }
    }

    stopFrameSync=syncExportFrames(FPS);
    await videoSource.play();
    // Só agora começa a gravar. Antes o recorder abria antes do play(), e todo
    // o tempo até o vídeo destravar virava quadro congelado no início do
    // arquivo — era o 'travamentinho no começo' e o motivo de o resultado
    // ficar mais longo que o original.
    await esperarVideoAndar(1500);
    recorder.start(1000);
    status.textContent='Gerando vídeo com áudio — 0% · mantenha esta aba na frente até terminar';button.textContent='Gerando vídeo…';
    const started=Date.now();
    const updateProgress=()=>{
      if(!videoExporting||!recorder||recorder.state==='inactive')return;
      const pct=Math.min(99,Math.floor((Date.now()-started)/(duration*1000)*100));
      status.textContent='Gerando vídeo com áudio — '+pct+'%';
      if(pct<100)requestAnimationFrame(updateProgress);
    };
    requestAnimationFrame(updateProgress);
    endedHandler=()=>{
      // Mantém o desenho por um instante: parar o loop antes do recorder
      // deixava o último trecho sem quadro novo.
      setTimeout(()=>{
        if(stopFrameSync)stopFrameSync.stop();
        if(recorder.state!=='inactive')recorder.stop();
      },150);
    };
    videoSource.addEventListener('ended',endedHandler,{once:true});
    stopTimer=setTimeout(()=>{if(recorder.state!=='inactive')recorder.stop();},duration*1000+3000);
    stopWatchdog=setTimeout(()=>{if(recorder.state!=='inactive'){try{recorder.stop();}catch(_) {}}},duration*1000+15000);
    await Promise.race([completed,new Promise((_,reject)=>{completionTimer=setTimeout(()=>reject(new Error('A gravação não encerrou no tempo esperado. Tente novamente com um vídeo menor.')),duration*1000+20000);})]);
    if(!videoChunks.length)throw new Error('O navegador terminou a gravação sem gerar dados.');
    const actualMime=recorder.mimeType||mime;
    const ext=actualMime.toLowerCase().indexOf('mp4')>=0?'mp4':'webm';

    // Confere se a gravação realmente capturou quadros. Antes dava para baixar
    // um arquivo com 1 quadro por segundo sem nenhum aviso na tela.
    const desenhados=stopFrameSync?stopFrameSync.contador():0;
    const esperados=Math.max(1,Math.round(duration*FPS));
    const aproveitamento=desenhados/esperados;

    const blob=new Blob(videoChunks,{type:actualMime}),url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download='metadash-video.'+ext;document.body.appendChild(a);a.click();a.remove();
    setTimeout(()=>URL.revokeObjectURL(url),60000);

    let recado='Vídeo exportado em '+ext.toUpperCase()+' a '+FPS+'fps e '
             +(taxaVideo/1000000).toFixed(1)+' Mbps, acompanhando o arquivo original.';
    if(formato.nota)recado+=' '+formato.nota;
    // Alguns navegadores só gravam MP4 com áudio Opus. O Instagram aceita, mas
    // QuickTime e players antigos podem abrir sem som.
    if(ext==='mp4'&&actualMime.toLowerCase().indexOf('opus')>=0){
      recado+=' Obs.: seu navegador gravou o áudio em Opus — sobe normalmente no Instagram, mas pode abrir sem som no QuickTime. Para MP4 com áudio AAC, exporte pelo Safari.';
    }
    if(aproveitamento<0.8){
      recado+=' ⚠️ Atenção: só '+Math.round(aproveitamento*100)+'% dos quadros foram capturados ('
            +desenhados+' de '+esperados+'), então o vídeo pode ficar travado. ';
      recado+=(stopFrameSync.saiuDeFoco()
        ? 'A aba ficou em segundo plano durante a exportação — o navegador congela o desenho quando isso acontece. Refaça mantendo esta aba na frente.'
        : 'Feche as outras abas e programas pesados e tente de novo.');
    }
    status.textContent=recado;
  }catch(e){
    const active=recorderRef();
    if(active&&active.state!=='inactive'){try{active.stop();}catch(_) {}}
    status.textContent='Não foi possível exportar o MP4: '+(e&&e.message?e.message:'erro inesperado');
  }finally{
    if(stopTimer)clearTimeout(stopTimer);
    if(stopWatchdog)clearTimeout(stopWatchdog);
    if(completionTimer)clearTimeout(completionTimer);
    if(endedHandler)videoSource.removeEventListener('ended',endedHandler);
    overlayCache=null;
    if(stopFrameSync)stopFrameSync.stop();
    if(recordStream)recordStream.getTracks().forEach(track=>{if(track!==exportAudioStream.getAudioTracks()[0])track.stop();});
    if(canvasStream)canvasStream.getTracks().forEach(track=>track.stop());
    try{videoSource.pause();videoSource.muted=true;videoSource.loop=true;if(videoSource.readyState>0)videoSource.currentTime=0;}catch(_) {}
    cancelAnimationFrame(videoFrame);try{drawVideoFrame();}catch(_) {}
    videoRecorder=null;videoChunks=[];videoExporting=false;button.disabled=false;button.textContent='Exportar vídeo';
  }
});
let videoAutoplayAttempted=false;
videoSource.addEventListener('loadedmetadata',()=>{drawVideoFrame();});
videoSource.addEventListener('loadeddata',async()=>{
  drawVideoFrame();
  const status=document.getElementById('videoStatus');
  status.textContent='Vídeo carregado; iniciando a prévia…';
  if(!videoAutoplayAttempted){
    videoAutoplayAttempted=true;
    try{
      videoSource.muted=true;
      await videoSource.play();
      cancelAnimationFrame(videoFrame);
      videoLoop();
      status.textContent='Prévia do vídeo em reprodução. Os elementos da arte estão sobrepostos.';
    }catch(err){
      drawVideoFrame();
      status.textContent='Vídeo carregado. Se o fundo não se mover, clique em “Reproduzir prévia”.';
    }
  }
});
['canplay','playing','timeupdate','seeked'].forEach(ev=>videoSource.addEventListener(ev,()=>{
  drawVideoFrame();
  if(ev==='playing')document.getElementById('videoStatus').textContent='Prévia do vídeo em reprodução.';
}));
videoSource.addEventListener('error',()=>{
  drawVideoFrame();
  document.getElementById('videoStatus').textContent='Não foi possível decodificar este vídeo. Tente MP4 H.264 ou WebM. Verifique se o arquivo reproduz no navegador.';
});
// Modelos reutilizáveis: persistência local por navegador, sem alterar tabelas do MetaDash.
const MODEL_KEY='metadash_studio_models_v1';
function modelRead(){try{return JSON.parse(localStorage.getItem(MODEL_KEY)||'{"items":[],"defaultId":null}')}catch(e){return {items:[],defaultId:null}}}
function modelWrite(data){localStorage.setItem(MODEL_KEY,JSON.stringify(data));}
function imageSource(img){if(!img)return null;return img.src||null;}
function collectModel(){
 const ids=['artist','city','dateText','headline','accent','houseSize','houseY','artistSize','artistY','citySize','cityY','dateSize','dateY','phraseSize','phraseY'];
 const values={};ids.forEach(id=>values[id]=val(id));
 return {version:1,values,images:{background:imageSource(bgImage),house:imageSource(houseLogo),artist:imageSource(artistLogo)},video:{url:(videoSource.currentSrc||videoSource.src||'')},layers:graphicLayers.map(l=>({src:imageSource(l.img),title:l.title,x:l.x,y:l.y,scale:l.scale})),savedAt:new Date().toISOString()};
}
function refreshModels(selectId){const data=modelRead(),sel=document.getElementById('savedModels'),previous=selectId||sel.value;sel.innerHTML='<option value="">Selecione um modelo...</option>';data.items.forEach(m=>{const o=document.createElement('option');o.value=m.id;o.textContent=m.name+(m.id===data.defaultId?' ★ Padrão':'');sel.append(o)});if(data.items.some(m=>m.id===previous))sel.value=previous;}
function setModelStatus(t){document.getElementById('modelStatus').textContent=t;}
function loadModelImage(src,assign){if(!src){assign(null);return Promise.resolve()}return new Promise(resolve=>{const im=new Image();im.onload=()=>{assign(im);resolve(true)};im.onerror=()=>{assign(null);resolve(false)};im.src=src;});}
async function applyModel(m){if(!m||!m.state)return;Object.entries(m.state.values||{}).forEach(([id,v])=>{const el=document.getElementById(id);if(el)el.value=v;const lab=document.getElementById(id+'Label');if(lab&&/Size|Y$/.test(id))lab.textContent=v+'%';});const im=m.state.images||{};const loaded=await Promise.all([loadModelImage(im.background,x=>bgImage=x),loadModelImage(im.house,x=>houseLogo=x),loadModelImage(im.artist,x=>artistLogo=x)]);const v=m.state.video||{};if(v.url&&!v.url.startsWith('blob:')&&!v.url.startsWith('data:'))setVideoUrl(new URL(v.url,window.location.href).href);graphicLayers=[];layerCounter=0;for(const l of (m.state.layers||[])){if(!l.src)continue;await loadModelImage(l.src,img=>{if(img)graphicLayers.push({id:++layerCounter,img,title:l.title||'Elemento gráfico',x:Number(l.x)||540,y:Number(l.y)||960,scale:Number(l.scale)||1})})}updateLayerList();render();drawVideoFrame();setModelStatus('Modelo carregado.'+(loaded.some(x=>x===false)?' Algumas imagens não puderam ser restauradas; talvez tenham sido removidas ou o armazenamento do navegador tenha atingido o limite.':''));}
document.getElementById('saveModel').addEventListener('click',()=>{const name=document.getElementById('modelName').value.trim();if(!name){setModelStatus('Digite um nome para o modelo antes de salvar.');document.getElementById('modelName').focus();return;}const data=modelRead(),id='m_'+Date.now().toString(36);try{data.items.push({id,name,state:collectModel()});modelWrite(data);refreshModels(id);setModelStatus('Modelo “'+name+'” salvo neste navegador.');}catch(e){setModelStatus('Não foi possível salvar: o armazenamento do navegador pode estar cheio. Prefira imagens menores ou use arquivos da biblioteca.');}});
document.getElementById('loadModel').addEventListener('click',async()=>{const id=document.getElementById('savedModels').value,data=modelRead(),m=data.items.find(x=>x.id===id);if(!m){setModelStatus('Selecione um modelo.');return;}await applyModel(m);});
document.getElementById('setDefaultModel').addEventListener('click',()=>{const id=document.getElementById('savedModels').value,data=modelRead();if(!data.items.some(x=>x.id===id)){setModelStatus('Selecione um modelo para definir como padrão.');return;}data.defaultId=id;try{modelWrite(data);refreshModels(id);setModelStatus('Modelo definido como padrão neste navegador. Ele será aplicado ao abrir o Estúdio.');}catch(e){setModelStatus('Não foi possível definir o padrão.');}});
document.getElementById('deleteModel').addEventListener('click',()=>{const id=document.getElementById('savedModels').value,data=modelRead(),m=data.items.find(x=>x.id===id);if(!m){setModelStatus('Selecione um modelo para excluir.');return;}if(!confirm('Excluir o modelo “'+m.name+'”?'))return;data.items=data.items.filter(x=>x.id!==id);if(data.defaultId===id)data.defaultId=null;try{modelWrite(data);refreshModels();setModelStatus('Modelo excluído.');}catch(e){setModelStatus('Não foi possível atualizar os modelos.');}});
refreshModels();
// Aplica o modelo marcado como padrão ao abrir o estúdio.
(async()=>{const d=modelRead(),m=d.items.find(x=>x.id===d.defaultId);if(m)await applyModel(m);})();
render();drawVideoFrame();
// Aplica os materiais marcados como padrão ao abrir o estúdio.
if(STUDIO_DEFAULTS.fundo_imagem)loadImageFromUrl(STUDIO_DEFAULTS.fundo_imagem.url,'background');
if(STUDIO_DEFAULTS.logo_casa)loadImageFromUrl(STUDIO_DEFAULTS.logo_casa.url,'house');
if(STUDIO_DEFAULTS.logo_artista)loadImageFromUrl(STUDIO_DEFAULTS.logo_artista.url,'artist');
if(STUDIO_DEFAULTS.fundo_video){setVideoUrl(new URL(STUDIO_DEFAULTS.fundo_video.url,window.location.href).href);}
</script></body></html>
