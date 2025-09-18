document.addEventListener('DOMContentLoaded', function() {

  // =================== Create Button ===================
  document.body.addEventListener('click', function(e) {
    if(e.target && e.target.classList.contains('createButton')) {
      e.preventDefault();

      var wantsDiscount = confirm('Do you want a 5% discount on your next purchase?');
      var email = '';
      if(wantsDiscount) {
        email = RCSettings.wpUserEmail || prompt('Enter your email to receive the discount:');
        if(!email){ alert('Email required for discount'); return; }
      }

      try {
        localStorage.setItem('receptDiscount', wantsDiscount ? '1' : '0');
        localStorage.setItem('receptEmail', email || '');
      } catch(e){}

      window.location = RCSettings.create_page;
    }
  });

  // ================= Card Click Toggle =================
  document.querySelectorAll('.grouped-card').forEach(function(card){
      card.addEventListener('click', function(){
          var checkbox = card.querySelector('input[type="checkbox"]');
          checkbox.checked = !checkbox.checked;
          card.classList.toggle('selected', checkbox.checked);
      });
  });

  // =================== Form Submission ===================
  var form = document.getElementById('rcCreateReceptForm');
  if(form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      // ===== Validate Title =====
      var title = form.querySelector('input[name="title"]').value.trim();
      if(!title){
        alert('Please enter a title.');
        return;
      }

      // ===== Validate Products =====
      var selectedProducts = form.querySelectorAll('input[name="grouped_products[]"]:checked');
      if(selectedProducts.length < 2){
        alert('Please select at least 2 products for the recipe.');
        return;
      }

      // ===== Validate Category =====
      var selectedCat = form.querySelector('select[name="category"]').value;
      var newCat = form.querySelector('input[name="new_category"]').value.trim();
      if(!selectedCat && !newCat){
        alert('Please select or enter a category.');
        return;
      }

      // Validate at least 2 products
      var selected = form.querySelectorAll('input[name="grouped_products[]"]:checked');
      if(selected.length < 2){
          alert('Please select at least 2 products for the recipe.');
          return;
      }

      // ===== Prepare FormData =====
      var fd = new FormData(form);
      fd.append('action', 'rc_create_recept');
      fd.append('nonce', RCSettings.nonce);
      fd.append('new_category', newCat);

      // ===== Send AJAX =====
      fetch(RCSettings.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      })
      .then(resp => resp.json())
      .then(json => {
        if(json.success) {
          var msg = json.data.message || 'Recipe created.';
          if(json.data.coupon) msg += ' Coupon: ' + json.data.coupon;
          alert(msg);
          window.location = RCSettings.recept_page;
        } else {
          var err = (json.data && json.data.message) ? json.data.message : 'Error creating recipe';
          alert('Error: ' + err);
        }
      })
      .catch(err => {
        console.error(err);
        alert('Unexpected error (see console).');
      });
    });
  }

});
