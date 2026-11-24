<?php
// Gera a cena animada do caminhão do cliente (HTML com window.render(t)).
// uso: php gerar-cena.php "vermelho" "Scania" "R 450" > cena.html
require __DIR__.'/../../volmaster crm/packages/Webkul/ServiceOrder/src/Services/TruckSvg.php';
use Webkul\ServiceOrder\Services\TruckSvg;
$cor=$argv[1]??'vermelho'; $marca=$argv[2]??'Scania'; $modelo=$argv[3]??'R 450';
$sil=TruckSvg::silhueta($marca,$modelo,'caminhoes');
$truck=TruckSvg::render($cor,$marca,$sil);
?><!doctype html><meta charset="utf-8">
<style>
*{margin:0;padding:0}html,body{width:800px;height:450px;overflow:hidden;background:#000}
#c{position:relative;width:800px;height:450px;overflow:hidden;background:linear-gradient(180deg,#6DB6EA 0%,#A9D6F3 48%,#E9F4FB 72%)}
.layer{position:absolute;left:0;width:1600px;will-change:transform}
.wheel-spin{transform-origin:center;transform-box:fill-box}
</style>
<div id="c">
  <div style="position:absolute;right:70px;top:34px;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,#fff6d6 0%,rgba(255,240,190,.55) 35%,rgba(255,240,190,0) 70%)"></div>
  <!-- nuvens (fixas, leve flutuação) -->
  <svg id="clouds" style="position:absolute;left:0;top:0" width="800" height="300" viewBox="0 0 800 300" fill="#fff" opacity=".92">
    <g id="cl1"><ellipse cx="150" cy="80" rx="62" ry="17"/><ellipse cx="122" cy="68" rx="30" ry="20"/><ellipse cx="170" cy="62" rx="32" ry="22"/></g>
    <g id="cl2"><ellipse cx="520" cy="120" rx="76" ry="19"/><ellipse cx="490" cy="106" rx="34" ry="22"/><ellipse cx="545" cy="100" rx="38" ry="25"/></g>
    <g id="cl3" opacity=".8"><ellipse cx="690" cy="60" rx="46" ry="12"/><ellipse cx="672" cy="52" rx="20" ry="14"/></g>
  </svg>
  <!-- cidade ao longe (periodo 400) -->
  <svg class="layer" id="far" style="top:196px" height="140" viewBox="0 0 1600 140"></svg>
  <!-- colinas (periodo 400) -->
  <svg class="layer" id="hills" style="top:236px" height="110" viewBox="0 0 1600 110"></svg>
  <!-- arvores (periodo 400) -->
  <svg class="layer" id="trees" style="top:262px" height="110" viewBox="0 0 1600 110"></svg>
  <!-- faixa de grama + mureta + pista -->
  <div style="position:absolute;left:0;top:352px;width:800px;height:16px;background:linear-gradient(#7CC06A,#5BA24D)"></div>
  <svg class="layer" id="rail" style="top:338px" height="30" viewBox="0 0 1600 30"></svg>
  <div style="position:absolute;left:0;top:366px;width:800px;height:84px;background:linear-gradient(#6a6d72,#4c4f54)"></div>
  <div style="position:absolute;left:0;top:366px;width:800px;height:3px;background:#d8dade;opacity:.9"></div>
  <svg class="layer" id="dash" style="top:404px" height="10" viewBox="0 0 1600 10"></svg>
  <div style="position:absolute;left:0;top:440px;width:800px;height:10px;background:#3c3f43"></div>
  <!-- sombra + fumaça + caminhão -->
  <div id="shadow" style="position:absolute;left:96px;top:384px;width:600px;height:26px;border-radius:50%;background:radial-gradient(ellipse,rgba(0,0,0,.45),rgba(0,0,0,0) 70%)"></div>
  <svg id="puffs" style="position:absolute;left:0;top:0" width="800" height="450"></svg>
  <svg id="lines" style="position:absolute;left:0;top:0" width="800" height="450"></svg>
  <svg id="truckwrap" style="position:absolute;left:90px;top:137px;overflow:visible" width="620" height="260" viewBox="0 0 155 65">
    <g id="truck"><?= $truck ?></g>
  </svg>
  <svg style="position:absolute;left:0;top:0" width="800" height="450"><defs><radialGradient id="vg" cx=".5" cy=".5" r=".75"><stop offset=".6" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#001a33" stop-opacity=".28"/></radialGradient></defs><rect width="800" height="450" fill="url(#vg)"/></svg>
</div>
<script>
const LOOP=4, SPEED_ROAD=1144/LOOP;   // 286 px/s -> 6 voltas de roda em 4s
const $=id=>document.getElementById(id);
const NS='http://www.w3.org/2000/svg';
function el(tag,attrs,parent){const e=document.createElementNS(NS,tag);for(const k in attrs)e.setAttribute(k,attrs[k]);parent&&parent.appendChild(e);return e}
// --- constrói camadas periódicas (3 repetições lado a lado = cobre 1600px)
(function build(){
  const far=$('far'), hills=$('hills'), trees=$('trees'), rail=$('rail'), dash=$('dash');
  for(let r=0;r<4;r++){const o=r*400;
    // prédios ao longe
    [[10,60,22],[40,38,30],[74,70,20],[100,44,34],[140,58,24],[170,36,28],[206,66,22],[236,48,30],[272,40,26],[300,62,20],[330,34,30],[362,52,24]].forEach(([x,h,w],i)=>el('rect',{x:o+x,y:140-h,width:w,height:h,fill:i%2?'#bcd7ea':'#c9e0ef'},far));
    // colinas
    el('path',{d:`M${o},110 Q${o+90},20 ${o+200},70 T${o+400},110 Z`,fill:'#8fc7a0',opacity:.75},hills);
    el('path',{d:`M${o},110 Q${o+60},58 ${o+140},84 T${o+300},96 T${o+400},110 Z`,fill:'#6fb585'},hills);
    // árvores (2 por período, alturas diferentes)
    [[60,64,'#2f8a46'],[170,50,'#3c9a52'],[300,70,'#2a7f3f']].forEach(([x,h,c])=>{
      el('rect',{x:o+x-3,y:110-26,width:6,height:26,fill:'#6b4b2e'},trees);
      el('ellipse',{cx:o+x,cy:110-26-h*0.35,rx:h*0.42,ry:h*0.5,fill:c},trees);
      el('ellipse',{cx:o+x-h*0.14,cy:110-26-h*0.5,rx:h*0.25,ry:h*0.3,fill:'rgba(255,255,255,.12)'},trees);});
  }
  // mureta com postes (período 143)
  for(let i=0;i<12;i++){const x=i*143;
    el('rect',{x:x,y:12,width:143,height:5,fill:'#cfd4d9'},rail);
    el('rect',{x:x+10,y:6,width:5,height:20,fill:'#8e969e'},rail);
    el('rect',{x:x+82,y:6,width:5,height:20,fill:'#8e969e'},rail);}
  // faixa tracejada (período 143)
  for(let i=0;i<12;i++)el('rect',{x:i*143+20,y:2,width:78,height:6,rx:2,fill:'#f5f5f5',opacity:.95},dash);
})();
// --- caminhão: preparo
const wheels=[...document.querySelectorAll('#truck .wheel-spin')];
// remove animação CSS: controlamos por script
function mod(a,b){return ((a%b)+b)%b}
function render(t){
  const ph=t/LOOP;                       // 0..1
  const sx=(p)=>`translateX(${-mod(ph*p.dist,p.per)}px)`;
  $('far').style.transform=sx({dist:400,per:400});
  $('hills').style.transform=sx({dist:400*2,per:400});
  $('trees').style.transform=sx({dist:400*4,per:400});
  $('rail').style.transform=sx({dist:1144,per:143});
  $('dash').style.transform=sx({dist:1144,per:143});
  // nuvens flutuam bem de leve
  $('cl1').setAttribute('transform',`translate(${Math.sin(ph*2*Math.PI)*6},0)`);
  $('cl2').setAttribute('transform',`translate(${Math.sin(ph*2*Math.PI+1.5)*8},0)`);
  $('cl3').setAttribute('transform',`translate(${Math.sin(ph*2*Math.PI+3)*5},0)`);
  // rodas: 6 voltas completas
  wheels.forEach(w=>w.style.transform=`rotate(${ph*360*6}deg)`);
  // suspensão: balanço do chassi (6 ciclos) + leve inclinação da cabine
  const b=Math.sin(ph*2*Math.PI*6), b2=Math.sin(ph*2*Math.PI*12+0.8);
  $('truck').setAttribute('transform',`translate(0,${(-0.35-0.28*b-0.12*b2).toFixed(3)}) rotate(${(0.12*b).toFixed(3)} 70 52)`);
  $('shadow').style.transform=`scaleX(${1+0.01*b})`;
  // fumaça do escapamento: 8 baforadas em loop
  const pf=$('puffs'); pf.innerHTML='';
  const ex = document.querySelector('#truck #exhaust circle');
  const exX = <?= $sil==='bau' ? 98 : 91.8 ?>, exY = <?= $sil==='bau' ? 6.4 : 2.4 ?>;
  const baseX = 90 + exX*4, baseY = 137 + exY*4;
  for(let i=0;i<8;i++){
    const age=mod(t-i*0.5,LOOP); if(age>1.5) continue;
    const k=age/1.5;
    el('circle',{cx:baseX-k*150,cy:baseY-k*46-4*Math.sin(k*9+i),r:7+k*20,fill:'#dfe6ec',opacity:(0.55*(1-k)).toFixed(3)},pf);
  }
  // linhas de velocidade atrás do caminhão
  const ln=$('lines'); ln.innerHTML='';
  for(let i=0;i<6;i++){
    const age=mod(t/1.0+i*0.37,1);              // 1s por linha; 4 voltas em 4s
    const y=[250,282,314,342,372,398][i], x0=96-age*70, len=40+(i%3)*26;
    el('rect',{x:x0-len,y:y,width:len,height:2.4,rx:1.2,fill:'#fff',opacity:(0.5*Math.sin(age*Math.PI)).toFixed(3)},ln);
  }
}
render(0);
</script>
