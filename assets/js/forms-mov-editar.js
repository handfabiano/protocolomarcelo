// forms-mov-editar.js (short)
document.addEventListener('DOMContentLoaded', function(){
  // MOV
  document.querySelectorAll('form.mn-form').forEach(function(form){
    const act = form.querySelector('input[name="action"]'); if(!act) return;
    // comum: validação de arquivo, anti-duplo submit, warn de saída
    const file = form.querySelector('input[type=file]'); const hint = form.querySelector('.mn-file-hint');
    function size(n){ return (n/1024/1024).toFixed(2)+' MB'; }
    if (file && hint){
      file.addEventListener('change', ()=>{
        hint.textContent=''; const f=file.files && file.files[0]; if(!f) return;
        const max = parseInt(file.dataset.max||'0',10) || 10485760;
        if (f.size>max){ hint.textContent = 'Arquivo excede o limite ('+size(f.size)+' > '+size(max)+')'; hint.style.color='#b3261e'; file.value=''; }
        else { hint.textContent='Selecionado: '+f.name+' ('+size(f.size)+')'; hint.style.color=''; }
      });
    }
    const btn = form.querySelector('button[type=submit]');
    form.addEventListener('submit', ()=>{ if(btn){ btn.disabled=true; setTimeout(()=>{ btn.disabled=false; }, 4000); } });
    let dirty=false; form.addEventListener('input', ()=> dirty=true, {once:true}); window.addEventListener('beforeunload', e=>{ if(!dirty) return; e.preventDefault(); e.returnValue=''; });

    // específico: data padrão para movimentar
    if (act.value==='mn_save_movimentacao'){
      const d = form.querySelector('#mn_data_mov'); if (d && !d.value) d.value = new Date().toISOString().slice(0,10);
    }
    // específico: máscara número no editar
    if (act.value==='mn_save_editar'){
      const n = form.querySelector('#mn_numero_edit'); function mask(v){ v=(v||'').replace(/[^0-9/]/g,''); if(/^\d{1,6}$/.test(v)) v = v + '/' + (new Date().getFullYear()); return v; }
      if (n){ n.addEventListener('input', e=>{ const p=e.target.selectionStart; e.target.value=mask(e.target.value); try{e.target.setSelectionRange(p,p);}catch(_){} }); n.addEventListener('blur', e=> e.target.value=mask(e.target.value)); }
    }
  });
});