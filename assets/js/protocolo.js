(function(){
  function $(sel,ctx){ return (ctx||document).querySelector(sel); }
  function on(el,ev,cb){ if(el) el.addEventListener(ev,cb); }

  const GAB = 'Gab. Ver. Marcelo Nunes';

  // Cadastro
  const tipo       = $('#mn_tipo');            // select Tipo (cadastro)
  const origem     = $('#mn_origem');
  const destino    = $('#mn_destino');
  const groupOrig  = $('#group_origem');
  const groupDest  = $('#group_destino');

  function toggleByTipo(el) {
    if (!el || !groupOrig || !groupDest) return;
    const v = el.value;
    if (v === 'Entrada'){
      groupOrig.style.display = '';
      groupDest.style.display = '';
      // Regra no cadastro: Entrada => destino = Gab (se vazio)
      if (destino && !destino.value) destino.value = GAB;
    } else if (v === 'Saída'){
      groupOrig.style.display = '';
      groupDest.style.display = '';
      // Regra no cadastro: Saída => origem = Gab (se vazio)
      if (origem && !origem.value) origem.value = GAB;
    } else {
      groupOrig.style.display = '';
      groupDest.style.display = '';
    }
  }
  if (tipo){ toggleByTipo(tipo); on(tipo,'change',()=>toggleByTipo(tipo)); }

  // Edição (só alterna exibição; NÃO força regra)
  const tipoEdit   = $('#mn_tipo_edit');
  const origemEdit = $('#mn_origem_edit');
  const destinoEdit= $('#mn_destino_edit');

  function toggleEdit(el){
    if (!el) return;
    // Apenas UI; não mexe em valores
    // Se quiser ocultar conforme tipo, descomente:
    // const v = el.value;
    // if (v === 'Entrada'){ /*...*/ } else if (v === 'Saída'){ /*...*/ }
  }
  if (tipoEdit){ toggleEdit(tipoEdit); on(tipoEdit,'change',()=>toggleEdit(tipoEdit)); }

  // Melhor UX: clique no número copia para clipboard
  document.addEventListener('click', function(e){
    const a = e.target.closest('.mn-numero');
    if (!a) return;
    e.preventDefault();
    navigator.clipboard?.writeText(a.textContent.trim());
    a.classList.add('copied');
    setTimeout(()=>a.classList.remove('copied'), 600);
    // também navega:
    window.location.href = a.getAttribute('href');
  }, false);
})();
(function(){
  function $(sel,ctx){ return (ctx||document).querySelector(sel); }
  function on(el,ev,cb){ if(el) el.addEventListener(ev,cb); }

  const GAB = 'Gab. Ver. Marcelo Nunes';

  // Cadastro
  const tipo       = $('#mn_tipo');            // select Tipo (cadastro)
  const origem     = $('#mn_origem');
  const destino    = $('#mn_destino');
  const groupOrig  = $('#group_origem');
  const groupDest  = $('#group_destino');

  function toggleByTipo(el) {
    if (!el || !groupOrig || !groupDest) return;
    const v = el.value;
    if (v === 'Entrada'){
      groupOrig.style.display = '';
      groupDest.style.display = '';
      // Regra no cadastro: Entrada => destino = Gab (se vazio)
      if (destino && !destino.value) destino.value = GAB;
    } else if (v === 'Saída'){
      groupOrig.style.display = '';
      groupDest.style.display = '';
      // Regra no cadastro: Saída => origem = Gab (se vazio)
      if (origem && !origem.value) origem.value = GAB;
    } else {
      groupOrig.style.display = '';
      groupDest.style.display = '';
    }
  }
  if (tipo){ toggleByTipo(tipo); on(tipo,'change',()=>toggleByTipo(tipo)); }

  // Edição (só alterna exibição; NÃO força regra)
  const tipoEdit   = $('#mn_tipo_edit');
  const origemEdit = $('#mn_origem_edit');
  const destinoEdit= $('#mn_destino_edit');

  function toggleEdit(el){
    if (!el) return;
    // Apenas UI; não mexe em valores
    // Se quiser ocultar conforme tipo, descomente:
    // const v = el.value;
    // if (v === 'Entrada'){ /*...*/ } else if (v === 'Saída'){ /*...*/ }
  }
  if (tipoEdit){ toggleEdit(tipoEdit); on(tipoEdit,'change',()=>toggleEdit(tipoEdit)); }

  // Melhor UX: clique no número copia para clipboard
  document.addEventListener('click', function(e){
    const a = e.target.closest('.mn-numero');
    if (!a) return;
    e.preventDefault();
    navigator.clipboard?.writeText(a.textContent.trim());
    a.classList.add('copied');
    setTimeout(()=>a.classList.remove('copied'), 600);
    // também navega:
    window.location.href = a.getAttribute('href');
  }, false);
})();
