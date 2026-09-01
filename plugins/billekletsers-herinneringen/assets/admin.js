(function(){
  'use strict';
  document.addEventListener('DOMContentLoaded', function(){
    var search = document.getElementById('bkpr-item-search');
    var type = document.getElementById('bkpr-type-filter');
    var clear = document.getElementById('bkpr-clear-filters');
    var count = document.getElementById('bkpr-result-count');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.bkpr-item-row'));
    if (!rows.length || !search || !type) return;
    function apply(){
      var q = (search.value || '').toLowerCase().trim();
      var t = type.value || '';
      var visible = 0;
      rows.forEach(function(row){
        var okQ = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
        var okT = !t || row.getAttribute('data-type') === t;
        var show = okQ && okT;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      if (count) count.textContent = visible + ' van ' + rows.length + ' zichtbaar';
    }
    search.addEventListener('input', apply);
    type.addEventListener('change', apply);
    if (clear) clear.addEventListener('click', function(){ search.value=''; type.value=''; apply(); });
    apply();
  });
})();

(function(){
  'use strict';
  function updateUserPicker(picker){
    if(!picker) return;
    var summary=picker.querySelector('[data-bkpr-user-summary]');
    if(!summary) return;
    var names=Array.prototype.slice.call(picker.querySelectorAll('input[type="checkbox"]:checked')).map(function(input){
      var label=input.closest('label');
      var span=label ? label.querySelector('span') : null;
      return span ? span.textContent.trim() : '';
    }).filter(Boolean);
    summary.textContent=names.length===0?'Geen gebruiker gekoppeld':(names.length<=2?names.join(', '):names.length+' gebruikers gekoppeld');
  }
  document.addEventListener('DOMContentLoaded',function(){
    Array.prototype.forEach.call(document.querySelectorAll('.bkpr-user-picker'),updateUserPicker);
  });
  document.addEventListener('change',function(event){
    var checkbox=event.target.closest('.bkpr-user-picker input[type="checkbox"]');
    if(checkbox) updateUserPicker(checkbox.closest('.bkpr-user-picker'));
  });
  document.addEventListener('click',function(event){
    if(event.target.closest('.bkpr-user-picker')) return;
    Array.prototype.forEach.call(document.querySelectorAll('.bkpr-user-picker[open]'),function(picker){picker.removeAttribute('open');});
  });
})();
