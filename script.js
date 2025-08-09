// --- Cart persistence + operations (improved) ---

// debounce helper
function debounce(fn, wait = 250){
  let t;
  return function(...args){
    clearTimeout(t);
    t = setTimeout(()=> fn.apply(this, args), wait);
  }
}

const saveCartDebounced = debounce(() => {
  try { localStorage.setItem('pimpale_cart', JSON.stringify(cart)); }
  catch(e){ console.warn('Could not save cart', e); }
  updateCartUI();
}, 200);

// Save immediately (used rarely)
function saveCart(){
  try { localStorage.setItem('pimpale_cart', JSON.stringify(cart)); }
  catch(e){ console.warn('Could not save cart', e); }
  updateCartUI();
}

function loadCart(){
  try {
    const raw = localStorage.getItem('pimpale_cart');
    cart = raw ? JSON.parse(raw) : [];
  } catch(e) {
    cart = [];
  }
  updateCartUI();
}

// Cart operations
function addToCart(id, qty = 1){
  qty = Number(qty) || 1;
  if(qty <= 0) qty = 1;
  const p = products.find(x => x.id === id);
  if(!p) return;
  const existing = cart.find(i => i.id === id);
  if(existing){ existing.qty = Number(existing.qty) + qty; }
  else { cart.push({id: p.id, name: p.name, price: p.price, qty}); }
  saveCartDebounced();
  toast(`${p.name} added to cart`);
}

function updateQuantity(id, delta){
  const item = cart.find(i => i.id === id);
  if(!item) return;
  item.qty = Number(item.qty) + Number(delta);
  if(item.qty <= 0){ cart = cart.filter(i => i.id !== id); }
  saveCartDebounced();
}

function removeItem(id){ cart = cart.filter(i => i.id !== id); saveCartDebounced(); }

function clearCart(){ cart = []; saveCartDebounced(); }

// Totals & counts
function cartTotal(){
  const total = cart.reduce((s,i) => s + (Number(i.price) * Number(i.qty)), 0);
  // format Indian locale
  return total;
}
function cartCount(){ return cart.reduce((s,i) => s + Number(i.qty), 0); }

// Render cart UI
function updateCartUI(){
  const countEl = document.getElementById('cartCount');
  const itemsEl = document.getElementById('cartItems');
  const totalEl = document.getElementById('cartTotal');
  countEl.textContent = cartCount();
  totalEl.textContent = `₹${cartTotal().toLocaleString('en-IN')}`;
  itemsEl.innerHTML = '';
  if(cart.length === 0){ itemsEl.innerHTML = '<div class="note">Your cart is empty.</div>'; return; }
  cart.forEach(item => {
    const imgSrc = (products.find(p => p.id === item.id) || {}).img || '';
    const row = document.createElement('div'); row.className = 'cart-row';
    row.innerHTML = `
      <img src="${imgSrc}" alt="${item.name}" loading="lazy">
      <div style="flex:1">
        <div style="display:flex;justify-content:space-between;align-items:center"><div style="font-weight:700">${item.name}</div><div class="price">₹${item.price}/kg</div></div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
          <div class="qty">
            <button aria-label="Decrease quantity of ${item.name}" onclick="updateQuantity('${item.id}', -1)">-</button>
            <div class="note" style="min-width:26px;text-align:center">${item.qty} kg</div>
            <button aria-label="Increase quantity of ${item.name}" onclick="updateQuantity('${item.id}', 1)">+</button>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end">
            <div style="font-weight:700">₹${(item.price * item.qty).toLocaleString('en-IN')}</div>
            <button class="ghost" style="margin-top:6px" onclick="removeItem('${item.id}')">Remove</button>
          </div>
        </div>
      </div>
    `;
    itemsEl.appendChild(row);
  });
}

// Quick order: single product prompt (validated)
function quickOrder(id){
  const p = products.find(x => x.id === id);
  if(!p) return;
  let qtyStr = prompt(`Quantity (kg) for ${p.name}:`, '10');
  if(qtyStr === null) return alert('Order cancelled');
  const qty = Number(qtyStr);
  if(!qty || qty <= 0){ return alert('Please enter a valid quantity (positive number).'); }
  const msg = `Hello, I want to order ${qty} kg of ${p.name} (₹${p.price}/kg) from Pimpale Traders.`;
  const link = `https://wa.me/919112295256?text=${encodeURIComponent(msg)}`;
  window.open(link, '_blank', 'noopener');
}
