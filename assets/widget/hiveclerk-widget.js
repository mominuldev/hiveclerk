var nt = Object.defineProperty;
var rt = (t, e, n) => e in t ? nt(t, e, { enumerable: !0, configurable: !0, writable: !0, value: n }) : t[e] = n;
var ge = (t, e, n) => rt(t, typeof e != "symbol" ? e + "" : e, n);
var oe, m, Le, O, me, Ue, je, ae, G, V, ze, _e, le, ue, te = {}, ne = [], ot = /acit|ex(?:s|g|n|p|$)|rph|grid|ows|mnc|ntw|ine[ch]|zoo|^ord|itera/i, ie = Array.isArray;
function N(t, e) {
  for (var n in e) t[n] = e[n];
  return t;
}
function pe(t) {
  t && t.parentNode && t.parentNode.removeChild(t);
}
function We(t, e, n) {
  var r, i, o, s = {};
  for (o in e) o == "key" ? r = e[o] : o == "ref" ? i = e[o] : s[o] = e[o];
  if (arguments.length > 2 && (s.children = arguments.length > 3 ? oe.call(arguments, 2) : n), typeof t == "function" && t.defaultProps != null) for (o in t.defaultProps) s[o] === void 0 && (s[o] = t.defaultProps[o]);
  return Q(t, s, r, i, null);
}
function Q(t, e, n, r, i) {
  var o = { type: t, props: e, key: n, ref: r, __k: null, __: null, __b: 0, __e: null, __c: null, constructor: void 0, __v: i ?? ++Le, __i: -1, __u: 0 };
  return i == null && m.vnode != null && m.vnode(o), o;
}
function X(t) {
  return t.children;
}
function Z(t, e) {
  this.props = t, this.context = e;
}
function B(t, e) {
  if (e == null) return t.__ ? B(t.__, t.__i + 1) : null;
  for (var n; e < t.__k.length; e++) if ((n = t.__k[e]) != null && n.__e != null) return n.__e;
  return typeof t.type == "function" ? B(t) : null;
}
function it(t) {
  if (t.__P && t.__d) {
    var e = t.__v, n = e.__e, r = [], i = [], o = N({}, e);
    o.__v = e.__v + 1, m.vnode && m.vnode(o), he(t.__P, o, e, t.__n, t.__P.namespaceURI, 32 & e.__u ? [n] : null, r, n ?? B(e), !!(32 & e.__u), i), o.__v = e.__v, o.__.__k[o.__i] = o, qe(r, o, i), e.__e = e.__ = null, o.__e != n && Re(o);
  }
}
function Re(t) {
  if ((t = t.__) != null && t.__c != null) return t.__e = t.__c.base = null, t.__k.some(function(e) {
    if (e != null && e.__e != null) return t.__e = t.__c.base = e.__e;
  }), Re(t);
}
function be(t) {
  (!t.__d && (t.__d = !0) && O.push(t) && !re.__r++ || me != m.debounceRendering) && ((me = m.debounceRendering) || Ue)(re);
}
function re() {
  try {
    for (var t, e = 1; O.length; ) O.length > e && O.sort(je), t = O.shift(), e = O.length, it(t);
  } finally {
    O.length = re.__r = 0;
  }
}
function Ve(t, e, n, r, i, o, s, l, p, c, _) {
  var h, a, d, g, T, y, w = r && r.__k || ne, v = e.length;
  for (p = at(n, e, w, p, v), h = 0; h < v; h++) (d = n.__k[h]) != null && (a = d.__i != -1 && w[d.__i] || te, d.__i = h, y = he(t, d, a, i, o, s, l, p, c, _), g = d.__e, d.ref && a.ref != d.ref && (a.ref && fe(a.ref, null, d), _.push(d.ref, d.__c || g, d)), T == null && g != null && (T = g), 4 & d.__u ? (p = Je(d, p, t), a.__e && (a.__e = null)) : typeof d.type == "function" && y !== void 0 ? p = y : g && (p = g.nextSibling), d.__u &= -7);
  return n.__e = T, p;
}
function at(t, e, n, r, i) {
  var o, s, l, p, c, _ = n.length, h = _, a = 0;
  for (t.__k = new Array(i), o = 0; o < i; o++) (s = e[o]) != null && typeof s != "boolean" && typeof s != "function" ? (typeof s == "string" || typeof s == "number" || typeof s == "bigint" || s.constructor == String ? s = t.__k[o] = Q(null, s, null, null, null) : ie(s) ? s = t.__k[o] = Q(X, { children: s }, null, null, null) : s.constructor === void 0 && s.__b > 0 ? s = t.__k[o] = Q(s.type, s.props, s.key, s.ref ? s.ref : null, s.__v) : t.__k[o] = s, p = o + a, s.__ = t, s.__b = t.__b + 1, l = null, (c = s.__i = st(s, n, p, h)) != -1 && (h--, (l = n[c]) && (l.__u |= 2)), l == null || l.__v == null ? (c == -1 && (i > _ ? a-- : i < _ && a++), typeof s.type != "function" && (s.__u |= 4)) : c != p && (c == p - 1 ? a-- : c == p + 1 ? a++ : (c > p ? a-- : a++, s.__u |= 4))) : t.__k[o] = null;
  if (h) for (o = 0; o < _; o++) (l = n[o]) != null && (2 & l.__u) == 0 && (l.__e == r && (r = B(l)), Ye(l, l));
  return r;
}
function Je(t, e, n) {
  var r, i;
  if (typeof t.type == "function") {
    for (r = t.__k, i = 0; r && i < r.length; i++) r[i] && (r[i].__ = t, e = Je(r[i], e, n));
    return e;
  }
  t.__e != e && (e && t.type && !e.parentNode && (e = B(t)), e = n.insertBefore(t.__e, e || null));
  do
    e = e && e.nextSibling;
  while (e != null && e.nodeType == 8);
  return e;
}
function st(t, e, n, r) {
  var i, o, s, l = t.key, p = t.type, c = e[n], _ = c != null && (2 & c.__u) == 0;
  if (c === null && l == null || _ && l == c.key && p == c.type) return n;
  if (r > (_ ? 1 : 0)) {
    for (i = n - 1, o = n + 1; i >= 0 || o < e.length; ) if ((c = e[s = i >= 0 ? i-- : o++]) != null && (2 & c.__u) == 0 && l == c.key && p == c.type) return s;
  }
  return -1;
}
function xe(t, e, n) {
  e[0] == "-" ? t.setProperty(e, n ?? "") : t[e] = n == null ? "" : typeof n != "number" || ot.test(e) ? n : n + "px";
}
function Y(t, e, n, r, i) {
  var o, s;
  e: if (e == "style") if (typeof n == "string") t.style.cssText = n;
  else {
    if (typeof r == "string" && (t.style.cssText = r = ""), r) for (e in r) n && e in n || xe(t.style, e, "");
    if (n) for (e in n) r && n[e] == r[e] || xe(t.style, e, n[e]);
  }
  else if (e[0] == "o" && e[1] == "n") o = e != (e = e.replace(ze, "$1")), s = e.toLowerCase(), e = s in t || e == "onFocusOut" || e == "onFocusIn" ? s.slice(2) : e.slice(2), t.l || (t.l = {}), t.l[e + o] = n, n ? r ? n[V] = r[V] : (n[V] = _e, t.addEventListener(e, o ? ue : le, o)) : t.removeEventListener(e, o ? ue : le, o);
  else {
    if (i == "http://www.w3.org/2000/svg") e = e.replace(/xlink(H|:h)/, "h").replace(/sName$/, "s");
    else if (e != "width" && e != "height" && e != "href" && e != "list" && e != "form" && e != "tabIndex" && e != "download" && e != "rowSpan" && e != "colSpan" && e != "role" && e != "popover" && e in t) try {
      t[e] = n ?? "";
      break e;
    } catch {
    }
    typeof n == "function" || (n == null || n === !1 && e[4] != "-" ? t.removeAttribute(e) : t.setAttribute(e, e == "popover" && n == 1 ? "" : n));
  }
}
function ye(t) {
  return function(e) {
    if (this.l) {
      var n = this.l[e.type + t];
      if (e[G] == null) e[G] = _e++;
      else if (e[G] < n[V]) return;
      return n(m.event ? m.event(e) : e);
    }
  };
}
function he(t, e, n, r, i, o, s, l, p, c) {
  var _, h, a, d, g, T, y, w, v, $, P, F, D, z, I, L, H = e.type;
  if (e.constructor !== void 0) return null;
  128 & n.__u && (p = !!(32 & n.__u), o = [l = e.__e = n.__e]), (_ = m.__b) && _(e);
  e: if (typeof H == "function") {
    h = s.length;
    try {
      if (v = e.props, $ = H.prototype && H.prototype.render, P = (_ = H.contextType) && r[_.__c], F = _ ? P ? P.props.value : _.__ : r, n.__c ? w = (a = e.__c = n.__c).__ = a.__E : ($ ? e.__c = a = new H(v, F) : (e.__c = a = new Z(v, F), a.constructor = H, a.render = lt), P && P.sub(a), a.state || (a.state = {}), a.__n = r, d = a.__d = !0, a.__h = [], a._sb = []), $ && a.__s == null && (a.__s = a.state), $ && H.getDerivedStateFromProps != null && (a.__s == a.state && (a.__s = N({}, a.__s)), N(a.__s, H.getDerivedStateFromProps(v, a.__s))), g = a.props, T = a.state, a.__v = e, d) $ && H.getDerivedStateFromProps == null && a.componentWillMount != null && a.componentWillMount(), $ && a.componentDidMount != null && a.__h.push(a.componentDidMount);
      else {
        if ($ && H.getDerivedStateFromProps == null && v !== g && a.componentWillReceiveProps != null && a.componentWillReceiveProps(v, F), e.__v == n.__v || !a.__e && a.shouldComponentUpdate != null && a.shouldComponentUpdate(v, a.__s, F) === !1) {
          e.__v != n.__v && (a.props = v, a.state = a.__s, a.__d = !1), e.__e = n.__e, e.__k = n.__k, e.__k.some(function(M) {
            M && (M.__ = e);
          }), ne.push.apply(a.__h, a._sb), a._sb = [], a.__h.length && s.push(a), l = B(n);
          break e;
        }
        a.componentWillUpdate != null && a.componentWillUpdate(v, a.__s, F), $ && a.componentDidUpdate != null && a.__h.push(function() {
          a.componentDidUpdate(g, T, y);
        });
      }
      if (a.context = F, a.props = v, a.__P = t, a.__e = !1, D = m.__r, z = 0, $) a.state = a.__s, a.__d = !1, D && D(e), _ = a.render(a.props, a.state, a.context), ne.push.apply(a.__h, a._sb), a._sb = [];
      else do
        a.__d = !1, D && D(e), _ = a.render(a.props, a.state, a.context), a.state = a.__s;
      while (a.__d && ++z < 25);
      a.state = a.__s, a.getChildContext != null && (r = N(N({}, r), a.getChildContext())), $ && !d && a.getSnapshotBeforeUpdate != null && (y = a.getSnapshotBeforeUpdate(g, T)), I = _ != null && _.type === X && _.key == null ? Xe(_.props.children) : _, l = Ve(t, ie(I) ? I : [I], e, n, r, i, o, s, l, p, c), a.base = e.__e, e.__u &= -161, a.__h.length && s.push(a), w && (a.__E = a.__ = null);
    } catch (M) {
      if (s.length = h, e.__v = null, p || o != null) {
        if (M.then) {
          for (e.__u |= p ? 160 : 128; l && l.nodeType == 8 && l.nextSibling; ) l = l.nextSibling;
          o != null && (o[o.indexOf(l)] = null), e.__e = l;
        } else if (o != null) for (L = o.length; L--; ) pe(o[L]);
      } else e.__e = n.__e;
      e.__k == null && (e.__k = n.__k || []), M.then || Ke(e), m.__e(M, e, n);
    }
  } else o == null && e.__v == n.__v ? (e.__k = n.__k, e.__e = n.__e) : l = e.__e = ct(n.__e, e, n, r, i, o, s, p, c);
  return (_ = m.diffed) && _(e), 128 & e.__u ? void 0 : l;
}
function Ke(t) {
  t && (t.__c && (t.__c.__e = !0), t.__k && t.__k.some(Ke));
}
function qe(t, e, n) {
  for (var r = 0; r < n.length; r++) fe(n[r], n[++r], n[++r]);
  m.__c && m.__c(e, t), t.some(function(i) {
    try {
      t = i.__h, i.__h = [], t.some(function(o) {
        o.call(i);
      });
    } catch (o) {
      m.__e(o, i.__v);
    }
  });
}
function Xe(t) {
  return typeof t != "object" || t == null || t.__b > 0 ? t : ie(t) ? t.map(Xe) : t.constructor !== void 0 ? null : N({}, t);
}
function ct(t, e, n, r, i, o, s, l, p) {
  var c, _, h, a, d, g, T, y = n.props || te, w = e.props, v = e.type;
  if (v == "svg" ? i = "http://www.w3.org/2000/svg" : v == "math" ? i = "http://www.w3.org/1998/Math/MathML" : i || (i = "http://www.w3.org/1999/xhtml"), o != null) {
    for (c = 0; c < o.length; c++) if ((d = o[c]) && "setAttribute" in d == !!v && (v ? d.localName == v : d.nodeType == 3)) {
      t = d, o[c] = null;
      break;
    }
  }
  if (t == null) {
    if (v == null) return document.createTextNode(w);
    t = document.createElementNS(i, v, w.is && w), l && (m.__m && m.__m(e, o), l = !1), o = null;
  }
  if (v == null) y === w || l && t.data == w || (t.data = w);
  else {
    if (o = v == "textarea" && w.defaultValue != null ? null : o && oe.call(t.childNodes), !l && o != null) for (y = {}, c = 0; c < t.attributes.length; c++) y[(d = t.attributes[c]).name] = d.value;
    for (c in y) d = y[c], c == "dangerouslySetInnerHTML" ? h = d : c == "children" || c in w || c == "value" && "defaultValue" in w || c == "checked" && "defaultChecked" in w || Y(t, c, null, d, i);
    for (c in w) d = w[c], c == "children" ? a = d : c == "dangerouslySetInnerHTML" ? _ = d : c == "value" ? g = d : c == "checked" ? T = d : l && typeof d != "function" || y[c] === d || Y(t, c, d, y[c], i);
    if (_) l || h && (_.__html == h.__html || _.__html == t.innerHTML) || (t.innerHTML = _.__html), e.__k = [];
    else if (h && (t.innerHTML = ""), Ve(e.type == "template" ? t.content : t, ie(a) ? a : [a], e, n, r, v == "foreignObject" ? "http://www.w3.org/1999/xhtml" : i, o, s, o ? o[0] : n.__k && B(n, 0), l, p), o != null) for (c = o.length; c--; ) pe(o[c]);
    l && v != "textarea" || (c = "value", v == "progress" && g == null ? t.removeAttribute("value") : g != null && (g !== t[c] || v == "progress" && !g || v == "option" && g != y[c]) && Y(t, c, g, y[c], i), c = "checked", T != null && T != t[c] && Y(t, c, T, y[c], i));
  }
  return t;
}
function fe(t, e, n) {
  try {
    if (typeof t == "function") {
      var r = typeof t.__u == "function";
      r && t.__u(), r && e == null || (t.__u = t(e));
    } else t.current = e;
  } catch (i) {
    m.__e(i, n);
  }
}
function Ye(t, e, n) {
  var r, i;
  if (m.unmount && m.unmount(t), (r = t.ref) && (r.current && r.current != t.__e || fe(r, null, e)), (r = t.__c) != null) {
    if (r.componentWillUnmount) try {
      r.componentWillUnmount();
    } catch (o) {
      m.__e(o, e);
    }
    r.base = r.__P = r.__n = null;
  }
  if (r = t.__k) for (i = 0; i < r.length; i++) r[i] && Ye(r[i], e, n || typeof t.type != "function");
  n || pe(t.__e), t.__c = t.__ = t.__e = void 0;
}
function lt(t, e, n) {
  return this.constructor(t, n);
}
function ut(t, e, n) {
  var r, i, o, s;
  e == document && (e = document.documentElement), m.__ && m.__(t, e), i = (r = !1) ? null : e.__k, o = [], s = [], he(e, t = e.__k = We(X, null, [t]), i || te, te, e.namespaceURI, i ? null : e.firstChild ? oe.call(e.childNodes) : null, o, i ? i.__e : e.firstChild, r, s), qe(o, t, s), t.props.children = null;
}
oe = ne.slice, m = { __e: function(t, e, n, r) {
  for (var i, o, s; e = e.__; ) if ((i = e.__c) && !i.__) try {
    if ((o = i.constructor) && o.getDerivedStateFromError != null && (i.setState(o.getDerivedStateFromError(t)), s = i.__d), i.componentDidCatch != null && (i.componentDidCatch(t, r || {}), s = i.__d), s) return i.__E = i;
  } catch (l) {
    t = l;
  }
  throw t;
} }, Le = 0, Z.prototype.setState = function(t, e) {
  var n;
  n = this.__s != null && this.__s != this.state ? this.__s : this.__s = N({}, this.state), typeof t == "function" && (t = t(N({}, n), this.props)), t && N(n, t), t != null && this.__v && (e && this._sb.push(e), be(this));
}, Z.prototype.forceUpdate = function(t) {
  this.__v && (this.__e = !0, t && this.__h.push(t), be(this));
}, Z.prototype.render = X, O = [], Ue = typeof Promise == "function" ? Promise.prototype.then.bind(Promise.resolve()) : setTimeout, je = function(t, e) {
  return t.__v.__b - e.__v.__b;
}, re.__r = 0, ae = Math.random().toString(8), G = "__d" + ae, V = "__a" + ae, ze = /(PointerCapture)$|Capture$/i, _e = 0, le = ye(!1), ue = ye(!0);
var dt = 0;
function u(t, e, n, r, i, o) {
  e || (e = {});
  var s, l, p = e;
  if ("ref" in p) for (l in p = {}, e) l == "ref" ? s = e[l] : p[l] = e[l];
  var c = { type: t, props: p, key: n, ref: s, __k: null, __: null, __b: 0, __e: null, __c: null, constructor: void 0, __v: --dt, __i: -1, __u: 0, __source: i, __self: o };
  if (typeof t == "function" && (s = t.defaultProps)) for (l in s) p[l] === void 0 && (p[l] = s[l]);
  return m.vnode && m.vnode(c), c;
}
var K, x, se, we, q = 0, Ge = [], k = m, ke = k.__b, Se = k.__r, Te = k.diffed, Ce = k.__c, $e = k.unmount, Fe = k.__;
function ve(t, e) {
  k.__h && k.__h(x, t, q || e), q = 0;
  var n = x.__H || (x.__H = { __: [], __h: [] });
  return t >= n.__.length && n.__.push({}), n.__[t];
}
function E(t) {
  return q = 1, _t(Ze, t);
}
function _t(t, e, n) {
  var r = ve(K++, 2);
  if (r.t = t, !r.__c && (r.__ = [Ze(void 0, e), function(l) {
    var p = r.__N ? r.__N[0] : r.__[0], c = r.t(p, l);
    p !== c && (r.__N = [c, r.__[1]], r.__c.setState({}));
  }], r.__c = x, !x.__f)) {
    var i = function(l, p, c) {
      if (!r.__c.__H) return !0;
      var _ = !1, h = r.__c.props !== l;
      if (r.__c.__H.__.some(function(d) {
        if (d.__N) {
          _ = !0;
          var g = d.__[0];
          d.__ = d.__N, d.__N = void 0, g !== d.__[0] && (h = !0);
        }
      }), o) {
        var a = o.call(this, l, p, c);
        return _ ? a || h : a;
      }
      return !_ || h;
    };
    x.__f = !0;
    var o = x.shouldComponentUpdate, s = x.componentWillUpdate;
    x.componentWillUpdate = function(l, p, c) {
      if (this.__e) {
        var _ = o;
        o = void 0, i(l, p, c), o = _;
      }
      s && s.call(this, l, p, c);
    }, x.shouldComponentUpdate = i;
  }
  return r.__N || r.__;
}
function j(t, e) {
  var n = ve(K++, 3);
  !k.__s && Qe(n.__H, e) && (n.__ = t, n.u = e, x.__H.__h.push(n));
}
function R(t) {
  return q = 5, J(function() {
    return { current: t };
  }, []);
}
function J(t, e) {
  var n = ve(K++, 7);
  return Qe(n.__H, e) && (n.__ = t(), n.__H = e, n.__h = t), n.__;
}
function He(t, e) {
  return q = 8, J(function() {
    return t;
  }, e);
}
function pt() {
  for (var t; t = Ge.shift(); ) {
    var e = t.__H;
    if (t.__P && e) try {
      e.__h.some(ee), e.__h.some(de), e.__h = [];
    } catch (n) {
      e.__h = [], k.__e(n, t.__v);
    }
  }
}
k.__b = function(t) {
  x = null, ke && ke(t);
}, k.__ = function(t, e) {
  t && e.__k && e.__k.__m && (t.__m = e.__k.__m), Fe && Fe(t, e);
}, k.__r = function(t) {
  Se && Se(t), K = 0;
  var e = (x = t.__c).__H;
  e && (se === x ? (e.__h = [], x.__h = [], e.__.some(function(n) {
    n.__N && (n.__ = n.__N), n.u = n.__N = void 0;
  })) : (e.__h.some(ee), e.__h.some(de), e.__h = [], K = 0)), se = x;
}, k.diffed = function(t) {
  Te && Te(t);
  var e = t.__c;
  e && e.__H && (e.__H.__h.length && (Ge.push(e) !== 1 && we === k.requestAnimationFrame || ((we = k.requestAnimationFrame) || ht)(pt)), e.__H.__.some(function(n) {
    n.u && (n.__H = n.u, n.u = void 0);
  })), se = x = null;
}, k.__c = function(t, e) {
  e.some(function(n) {
    try {
      n.__h.some(ee), n.__h = n.__h.filter(function(r) {
        return !r.__ || de(r);
      });
    } catch (r) {
      e.some(function(i) {
        i.__h && (i.__h = []);
      }), e = [], k.__e(r, n.__v);
    }
  }), Ce && Ce(t, e);
}, k.unmount = function(t) {
  $e && $e(t);
  var e, n = t.__c;
  n && n.__H && (n.__H.__.some(function(r) {
    try {
      ee(r);
    } catch (i) {
      e = i;
    }
  }), n.__H = void 0, e && k.__e(e, n.__v));
};
var Ee = typeof requestAnimationFrame == "function";
function ht(t) {
  var e, n = function() {
    clearTimeout(r), Ee && cancelAnimationFrame(e), setTimeout(t);
  }, r = setTimeout(n, 35);
  Ee && (e = requestAnimationFrame(n));
}
function ee(t) {
  var e = x, n = t.__c;
  typeof n == "function" && (t.__c = void 0, n()), x = e;
}
function de(t) {
  var e = x;
  t.__c = t.__(), x = e;
}
function Qe(t, e) {
  return !t || t.length !== e.length || e.some(function(n, r) {
    return n !== t[r];
  });
}
function Ze(t, e) {
  return typeof e == "function" ? e(t) : e;
}
const ft = "hvc.session", Ae = "hvc.transport", ce = "hvc.visitor";
function De(t) {
  try {
    return window.sessionStorage.getItem(t);
  } catch {
    return null;
  }
}
function Ne(t, e) {
  try {
    window.sessionStorage.setItem(t, e);
  } catch {
  }
}
function Pe(t) {
  try {
    return window.localStorage.getItem(t);
  } catch {
    return null;
  }
}
function vt(t, e) {
  try {
    window.localStorage.setItem(t, e);
  } catch {
  }
}
class gt {
  constructor(e) {
    ge(this, "session", null);
    this.boot = e, this.session = this.restore();
  }
  /** The session token, obtaining one if needed. */
  async token() {
    if (this.session && this.session.expires > Date.now() + 3e4)
      return this.session.token;
    const e = await fetch(`${this.boot.rest_url}/public/session`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        agent: this.boot.agent.uuid,
        url: window.location.href,
        title: document.title,
        language: navigator.language,
        // Sent so the conversation carries the visit history that led to
        // it. Without this a lead's timeline starts at "lead captured".
        visitor: Pe(ce)
      })
    });
    if (!e.ok)
      throw new Error(`session ${e.status}`);
    const n = await e.json(), r = {
      token: n.data.session,
      conversation: n.data.conversation,
      expires: n.data.expires_at ? Date.parse(n.data.expires_at) : Date.now() + 36e5
    };
    return this.session = r, Ne(this.key(), JSON.stringify(r)), r.token;
  }
  /** The conversation this session is attached to, if one is open. */
  conversation() {
    return this.session?.conversation ?? null;
  }
  /** Discard the session. Called when the server says it has expired. */
  forget() {
    this.session = null;
    try {
      window.sessionStorage.removeItem(this.key());
    } catch {
    }
  }
  /** The transport this session settled on, if it has. */
  transport() {
    const e = De(Ae);
    return e === "poll" || e === "sse" ? e : null;
  }
  /**
   * Remember that streaming did not work here.
   *
   * Recorded per session, not per message. The detection costs a 2.5
   * second wait, and a host that buffered the first reply will buffer
   * every reply — paying that wait on each message would make the
   * fallback more annoying than the problem.
   */
  rememberTransport(e) {
    Ne(Ae, e);
  }
  /**
   * Ask for a person (FR-WGT-07).
   *
   * Returns whether the request was accepted. Repeating it is safe: the
   * server treats a second ask as the same ask, so a visitor pressing the
   * button twice does not email the site owner twice.
   */
  async handoff() {
    if (!this.session)
      return !1;
    const e = await fetch(`${this.boot.rest_url}/public/chat/handoff`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-HVC-Session": this.session.token
      },
      body: JSON.stringify({ url: window.location.href })
    });
    return e.status === 401 && this.forget(), e.ok;
  }
  /** Restore the transcript after a page navigation. */
  async history() {
    return (await this.transcript()).messages;
  }
  /**
   * The transcript and what state the conversation is in.
   *
   * The status rides along with the messages because the widget needs
   * both at exactly the same moments — on open, and on every poll while a
   * colleague is answering — and two round trips for one screen state is
   * one more than the visitor's connection deserves.
   */
  async transcript() {
    if (!this.session)
      return { messages: [], awaitingHuman: !1, humanActive: !1 };
    const e = await fetch(`${this.boot.rest_url}/public/chat/history`, {
      headers: { "X-HVC-Session": this.session.token }
    });
    if (!e.ok)
      return e.status === 401 && this.forget(), { messages: [], awaitingHuman: !1, humanActive: !1 };
    const n = await e.json();
    return {
      messages: n.data.messages.map((r) => ({
        id: r.id,
        role: r.role,
        text: r.text,
        citations: r.citations ?? [],
        fromHuman: r.from_human === !0,
        rating: r.rating === 1 ? 1 : r.rating === -1 ? -1 : null
      })),
      awaitingHuman: n.data.awaiting_human === !0,
      humanActive: n.data.status === "handoff_active"
    };
  }
  /**
   * Tell the server this page was seen (FR-LED-07).
   *
   * Fire and forget, and silent on failure. Telemetry that surfaces an
   * error to a visitor reading a blog post is worse than telemetry that
   * is missing, and every page-context scoring rule already treats an
   * absent count as zero.
   */
  async pageView() {
    try {
      const e = await fetch(`${this.boot.rest_url}/public/events`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          type: "page_view",
          visitor: Pe(ce),
          url: window.location.href,
          title: document.title,
          language: navigator.language
        })
      });
      if (e.status !== 200)
        return;
      const n = await e.json();
      n.data?.visitor && vt(ce, n.data.visitor);
    } catch {
    }
  }
  /**
   * Submit the in-chat capture form (FR-LED-01).
   *
   * The response says only whether it was accepted. A score, a stage or a
   * lead id echoed back to the browser would be the customer's own
   * commercial assessment of the person reading it.
   */
  async capture(e) {
    const n = await this.token(), r = await fetch(`${this.boot.rest_url}/public/leads`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-HVC-Session": n
      },
      body: JSON.stringify(e)
    });
    return r.status === 401 && this.forget(), r.ok;
  }
  /** Record a thumbs up or down. */
  async rate(e, n) {
    this.session && await fetch(`${this.boot.rest_url}/public/chat/feedback`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-HVC-Session": this.session.token
      },
      body: JSON.stringify({ message: e, rating: n })
    });
  }
  /** Base URL for the chat routes. */
  url(e) {
    return `${this.boot.rest_url}${e}`;
  }
  key() {
    return `${ft}.${this.boot.agent.uuid}`;
  }
  restore() {
    const e = De(this.key());
    if (!e)
      return null;
    try {
      const n = JSON.parse(e);
      return n.expires > Date.now() ? n : null;
    } catch {
      return null;
    }
  }
}
const mt = 2500, bt = 260, xt = 9e4;
async function yt(t, e, n, r) {
  return n === "poll" ? (await Ie(t, e, r), "poll") : await wt(t, e, r) ? "sse" : (await Ie(t, e, r), "poll");
}
async function wt(t, e, n) {
  const r = await t.token(), i = new AbortController();
  let o = !1;
  const s = window.setTimeout(() => {
    o || i.abort();
  }, mt);
  try {
    const l = await fetch(t.url("/public/chat/stream"), {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "text/event-stream",
        "X-HVC-Session": r
      },
      body: JSON.stringify({ message: e, url: window.location.href, title: document.title }),
      signal: i.signal
    });
    if (l.status === 401)
      return t.forget(), window.clearTimeout(s), n.onError("expired"), !0;
    if (!l.ok || !l.body)
      return window.clearTimeout(s), !1;
    const p = l.body.getReader(), c = new TextDecoder();
    let _ = "";
    for (; ; ) {
      const { done: h, value: a } = await p.read();
      if (h)
        break;
      o || (o = !0, window.clearTimeout(s)), _ += c.decode(a, { stream: !0 });
      const d = _.split(`

`);
      _ = d.pop() ?? "";
      for (const g of d)
        kt(g, n);
    }
    return window.clearTimeout(s), o;
  } catch {
    return window.clearTimeout(s), !1;
  }
}
function kt(t, e) {
  let n = "message", r = "";
  for (const o of t.split(`
`))
    o.startsWith(":") || (o.startsWith("event:") ? n = o.slice(6).trim() : o.startsWith("data:") && (r += (r ? `
` : "") + o.slice(5).replace(/^ /, "")));
  if (!r)
    return;
  let i;
  try {
    i = JSON.parse(r);
  } catch {
    return;
  }
  switch (n) {
    case "start":
      e.onStart(String(i.message_id ?? ""));
      break;
    case "delta":
      e.onDelta(String(i.text ?? ""));
      break;
    case "replace":
      e.onReplace(String(i.text ?? ""));
      break;
    case "citations":
      e.onCitations(i.citations ?? []);
      break;
    case "done":
      e.onDone(i);
      break;
    case "error":
      e.onError(String(i.message ?? ""));
      break;
  }
}
async function Ie(t, e, n) {
  const r = await t.token(), i = Tt();
  let o = !1;
  fetch(t.url("/public/chat/message"), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-HVC-Session": r
    },
    body: JSON.stringify({
      message: e,
      reference: i,
      url: window.location.href,
      title: document.title
    })
  }).catch(() => {
  });
  const l = Date.now() + xt;
  let p = 0;
  for (; ; ) {
    if (Date.now() > l) {
      n.onError("timeout");
      return;
    }
    await St(bt);
    const c = await fetch(
      t.url(`/public/chat/poll?message=${i}&cursor=${p}`),
      { headers: { "X-HVC-Session": r } }
    );
    if (c.status === 401) {
      t.forget(), n.onError("expired");
      return;
    }
    if (!c.ok)
      continue;
    const h = (await c.json()).data;
    if (!h.pending && (o || (o = !0, n.onStart(h.message_id ?? "")), h.replaced ? n.onReplace(h.text) : h.text && n.onDelta(h.text), p = h.cursor, h.complete)) {
      if (h.error) {
        n.onError(h.error.message);
        return;
      }
      h.citations?.length && n.onCitations(h.citations), n.onDone(h.done);
      return;
    }
  }
}
function St(t) {
  return new Promise((e) => window.setTimeout(e, t));
}
function Tt() {
  if (typeof crypto < "u" && typeof crypto.randomUUID == "function")
    return crypto.randomUUID();
  const t = new Uint8Array(16);
  if (typeof crypto < "u" && typeof crypto.getRandomValues == "function")
    crypto.getRandomValues(t);
  else
    for (let n = 0; n < 16; n += 1)
      t[n] = Math.floor(Math.random() * 256);
  t[6] = (t[6] ?? 0) & 15 | 64, t[8] = (t[8] ?? 0) & 63 | 128;
  const e = Array.from(t, (n) => n.toString(16).padStart(2, "0")).join("");
  return `${e.slice(0, 8)}-${e.slice(8, 12)}-${e.slice(12, 16)}-${e.slice(16, 20)}-${e.slice(20)}`;
}
const Ct = {
  open: "Open chat",
  close: "Close chat",
  minimise: "Minimise",
  placeholder: "Ask anything…",
  send: "Send",
  sending: "Sending",
  thinking: "Thinking",
  sources: "Sources",
  helpful: "This helped",
  notHelpful: "This didn't help",
  rated: "Thanks — noted.",
  retry: "Try again",
  offline: "That didn't send. Check your connection and try again.",
  expired: "This conversation timed out. Reload the page to start a new one.",
  subtitle: "Usually replies instantly",
  askHuman: "Talk to a person",
  waitingHuman: "Waiting for a colleague. You can keep typing — they will see it.",
  humanHere: "A colleague is answering. You can keep typing.",
  fromHuman: "From a colleague",
  captureTitle: "Want us to follow this up? Where should we send it?",
  captureEmail: "you@company.com",
  captureSend: "Send it",
  // Always present, and never a secondary-looking control. A capture
  // prompt that cannot be dismissed is a dark pattern, and it produces
  // junk addresses from people trying to get past it.
  captureDismiss: "Not now",
  captureThanks: "Thanks — we'll be in touch.",
  captureInvalid: "That doesn't look like an email address."
};
function $t(t) {
  return Ct;
}
const Ft = /(`[^`]+`)|(\*\*[^*]+\*\*)|(__[^_]+__)|(\*[^*\n]+\*)|(\[[^\]]+\]\([^)\s]+\))/;
function Ht(t) {
  const e = [], n = t.split(`
`);
  let r = [], i = null, o = 0;
  const s = () => {
    r.length && (e.push(/* @__PURE__ */ u("p", { children: Me(r.join(" ")) }, `p${o++}`)), r = []);
  }, l = () => {
    if (!i)
      return;
    const p = i.items.map((c, _) => /* @__PURE__ */ u("li", { children: Me(c) }, `li${_}`));
    e.push(
      i.ordered ? /* @__PURE__ */ u("ol", { children: p }, `l${o++}`) : /* @__PURE__ */ u("ul", { children: p }, `l${o++}`)
    ), i = null;
  };
  for (const p of n) {
    const c = p.trimEnd();
    if (!c.trim()) {
      s(), l();
      continue;
    }
    const _ = /^\s*[-*+]\s+(.*)$/.exec(c), h = /^\s*\d+[.)]\s+(.*)$/.exec(c);
    if (_ || h) {
      s();
      const a = !!h, d = _?.[1] ?? h?.[1];
      (!i || i.ordered !== a) && (l(), i = { ordered: a, items: [] }), i.items.push(d);
      continue;
    }
    l(), r.push(c.trim());
  }
  return s(), l(), e;
}
function Me(t) {
  const e = [];
  let n = t, r = 0;
  for (; ; ) {
    const i = Ft.exec(n);
    if (!i || i.index === void 0)
      break;
    i.index > 0 && e.push(n.slice(0, i.index));
    const o = i[0];
    e.push(Et(o, r++)), n = n.slice(i.index + o.length);
  }
  return n && e.push(n), e;
}
function Et(t, e) {
  if (t.startsWith("`"))
    return /* @__PURE__ */ u("code", { children: t.slice(1, -1) }, e);
  if (t.startsWith("**") || t.startsWith("__"))
    return /* @__PURE__ */ u("strong", { children: t.slice(2, -2) }, e);
  if (t.startsWith("*"))
    return /* @__PURE__ */ u("em", { children: t.slice(1, -1) }, e);
  const n = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(t);
  if (n) {
    const r = At(n[2] ?? "");
    return r ? /* @__PURE__ */ u("a", { href: r, target: "_blank", rel: "noopener noreferrer nofollow", children: n[1] }, e) : n[1] ?? t;
  }
  return t;
}
function At(t) {
  const e = t.trim();
  return e.startsWith("/") || e.startsWith("#") || /^https?:\/\//i.test(e) ? e : null;
}
function Dt({ message: t, labels: e, onRate: n }) {
  const r = t.role === "clerk", i = !!t.streaming && t.text === "";
  return /* @__PURE__ */ u("div", { class: `row ${t.role}`, children: /* @__PURE__ */ u("div", { class: "bubble", children: [
    t.fromHuman ? /* @__PURE__ */ u("div", { class: "from-human", children: e.fromHuman }) : null,
    i ? /* @__PURE__ */ u("div", { class: "typing", "aria-label": e.thinking, children: [
      /* @__PURE__ */ u("span", {}),
      /* @__PURE__ */ u("span", {}),
      /* @__PURE__ */ u("span", {})
    ] }) : Ht(t.text),
    r && t.citations.length > 0 && /* @__PURE__ */ u("div", { class: "sources", children: [
      /* @__PURE__ */ u("span", { class: "sr-only", children: e.sources }),
      t.citations.map(
        (o) => o.url ? /* @__PURE__ */ u(
          "a",
          {
            class: "source",
            href: o.url,
            target: "_blank",
            rel: "noopener noreferrer",
            children: [
              /* @__PURE__ */ u("span", { class: "caret", "aria-hidden": "true", children: "▸" }),
              o.heading_path || o.title
            ]
          },
          o.url + o.heading_path
        ) : /* @__PURE__ */ u("span", { class: "source", children: [
          /* @__PURE__ */ u("span", { class: "caret", "aria-hidden": "true", children: "▸" }),
          o.heading_path || o.title
        ] }, o.title + o.heading_path)
      )
    ] }),
    r && !t.fromHuman && !t.streaming && t.text !== "" && /* @__PURE__ */ u("div", { class: "feedback", children: t.rating ? /* @__PURE__ */ u("span", { class: "note", children: e.rated }) : /* @__PURE__ */ u(X, { children: [
      /* @__PURE__ */ u("button", { type: "button", "aria-label": e.helpful, onClick: () => n(t.id, 1), children: "▲" }),
      /* @__PURE__ */ u(
        "button",
        {
          type: "button",
          "aria-label": e.notHelpful,
          onClick: () => n(t.id, -1),
          children: "▼"
        }
      )
    ] }) })
  ] }) });
}
function Nt({ labels: t, consent: e, onSubmit: n, onDismiss: r }) {
  const [i, o] = E(""), [s, l] = E(!1), [p, c] = E(null), [_, h] = E(!1);
  return /* @__PURE__ */ u("form", { class: "capture", onSubmit: (d) => {
    d.preventDefault();
    const g = i.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(g)) {
      c(t.captureInvalid);
      return;
    }
    l(!0), c(null), n(g, _).then((T) => {
      l(!1), T || c(t.offline);
    }).catch(() => {
      l(!1), c(t.offline);
    });
  }, children: [
    /* @__PURE__ */ u("div", { class: "capture-title", children: t.captureTitle }),
    /* @__PURE__ */ u(
      "input",
      {
        class: "capture-input",
        type: "email",
        inputMode: "email",
        autocomplete: "email",
        "aria-label": t.captureTitle,
        placeholder: t.captureEmail,
        value: i,
        disabled: s,
        onInput: (d) => o(d.target.value)
      }
    ),
    e ? /* @__PURE__ */ u("label", { class: "capture-consent", children: [
      /* @__PURE__ */ u(
        "input",
        {
          type: "checkbox",
          checked: _,
          disabled: s,
          onChange: (d) => h(d.target.checked)
        }
      ),
      /* @__PURE__ */ u("span", { children: e })
    ] }) : null,
    p ? /* @__PURE__ */ u("div", { class: "capture-error", role: "alert", children: p }) : null,
    /* @__PURE__ */ u("div", { class: "capture-actions", children: [
      /* @__PURE__ */ u("button", { type: "submit", class: "capture-send", disabled: s, children: s ? t.sending : t.captureSend }),
      /* @__PURE__ */ u("button", { type: "button", class: "capture-dismiss", onClick: r, disabled: s, children: t.captureDismiss })
    ] })
  ] });
}
function Pt() {
  return /* @__PURE__ */ u("svg", { width: "22", height: "22", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ u(
    "path",
    {
      d: "M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.6-.7L3 21l1.9-5A8.2 8.2 0 0 1 4 11.5 8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5Z",
      stroke: "currentColor",
      "stroke-width": "1.8",
      "stroke-linecap": "round",
      "stroke-linejoin": "round"
    }
  ) });
}
function It() {
  return /* @__PURE__ */ u("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ u("path", { d: "m6 6 12 12M18 6 6 18", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round" }) });
}
function Mt() {
  return /* @__PURE__ */ u("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ u("path", { d: "M6 12h12", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round" }) });
}
function Ot() {
  return /* @__PURE__ */ u("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ u(
    "path",
    {
      d: "M12 19V5m0 0-6 6m6-6 6 6",
      stroke: "currentColor",
      "stroke-width": "2",
      "stroke-linecap": "round",
      "stroke-linejoin": "round"
    }
  ) });
}
const Bt = 2e3;
function Lt({ labels: t, busy: e, onSend: n }) {
  const r = R(null), i = () => {
    const o = r.current;
    if (!o)
      return;
    const s = o.value.trim();
    !s || e || (n(s), o.value = "", o.style.height = "auto");
  };
  return /* @__PURE__ */ u("div", { class: "composer", children: /* @__PURE__ */ u("div", { class: "field", children: [
    /* @__PURE__ */ u(
      "textarea",
      {
        ref: r,
        rows: 1,
        maxLength: Bt,
        placeholder: t.placeholder,
        "aria-label": t.placeholder,
        onInput: (o) => {
          const s = o.currentTarget;
          s.style.height = "auto", s.style.height = `${s.scrollHeight}px`;
        },
        onKeyDown: (o) => {
          o.key === "Enter" && !o.shiftKey && (o.preventDefault(), i());
        }
      }
    ),
    /* @__PURE__ */ u(
      "button",
      {
        type: "button",
        class: "send",
        disabled: e,
        "aria-label": e ? t.sending : t.send,
        onClick: i,
        children: /* @__PURE__ */ u(Ot, {})
      }
    )
  ] }) });
}
function Ut({ boot: t, host: e }) {
  const n = J(() => $t(t.agent.locale), [t.agent.locale]), r = J(() => new gt(t), [t]), [i, o] = E(!1), [s, l] = E(!1), [p, c] = E(null), [_, h] = E([]), [a, d] = E(!1), [g, T] = E(!1), [y, w] = E(!1), [v, $] = E(!1), P = R(null), F = R(null), D = R(null), z = R(r.transport() ?? "sse"), I = t.agent.greeting?.trim(), L = J(() => !I || _.length > 0 ? _ : [{ id: "greeting", role: "clerk", text: I, citations: [], rating: null }], [I, _]);
  j(() => {
    r.pageView();
  }, [r]), j(() => {
    !i || _.length > 0 || !r.conversation() || r.transcript().then((f) => {
      f.messages.length > 0 && h(f.messages), d(f.awaitingHuman), T(f.humanActive);
    });
  }, [i, _.length, r]), j(() => {
    if (!i || !a)
      return;
    const f = window.setInterval(() => {
      r.transcript().then((b) => {
        b.messages.length > 0 && h(b.messages), d(b.awaitingHuman), T(b.humanActive);
      });
    }, 8e3);
    return () => window.clearInterval(f);
  }, [i, a, r]), j(() => {
    const f = P.current;
    f && (f.scrollTop = f.scrollHeight);
  }, [L]), j(() => {
    if (!i)
      return;
    const f = (b) => {
      if (b.key === "Escape") {
        o(!1), F.current?.focus();
        return;
      }
      if (b.key !== "Tab")
        return;
      const C = D.current?.querySelectorAll("button, textarea, a[href]");
      if (!C || C.length === 0)
        return;
      const A = C[0], S = C[C.length - 1], U = e.shadowRoot?.activeElement;
      b.shiftKey && U === A ? (b.preventDefault(), S?.focus()) : !b.shiftKey && U === S && (b.preventDefault(), A?.focus());
    };
    return e.addEventListener("keydown", f), () => e.removeEventListener("keydown", f);
  }, [i, e]), j(() => {
    i && D.current?.querySelector("textarea")?.focus();
  }, [i]);
  const H = _.filter((f) => f.role === "visitor").length, M = t.capture?.enabled === !0 && !y && !v && !a && !s && H >= (t.capture.ask_after ?? 2), et = He(
    (f, b) => {
      h(
        (C) => C.map((A) => A.id === f ? { ...A, rating: b } : A)
      ), r.rate(f, b);
    },
    [r]
  ), tt = He(
    (f) => {
      c(null), l(!0);
      const b = `pending-${Date.now()}`;
      h((S) => [
        ...S,
        { id: `visitor-${Date.now()}`, role: "visitor", text: f, citations: [], rating: null },
        { id: b, role: "clerk", text: "", citations: [], streaming: !0, rating: null }
      ]);
      const C = (S) => {
        h(
          (U) => U.map((W) => W.id === b ? { ...W, ...S } : W)
        );
      };
      let A = "";
      yt(r, f, z.current, {
        onStart: () => {
        },
        onDelta: (S) => {
          A += S, C({ text: A });
        },
        onReplace: (S) => {
          A = S, C({ text: A });
        },
        onCitations: (S) => C({ citations: S }),
        onDone: (S) => {
          C({ streaming: !1 }), l(!1), S?.awaiting_human === !0 && (d(!0), h((U) => U.filter((W) => W.id !== b)));
        },
        onError: (S) => {
          C({ streaming: !1, failed: !0 }), l(!1), c(S === "expired" ? n.expired : n.offline);
        }
      }).then((S) => {
        z.current = S, r.rememberTransport(S);
      }).catch(() => {
        C({ streaming: !1, failed: !0 }), l(!1), c(n.offline);
      });
    },
    [r, n]
  );
  if (!i) {
    const f = t.agent.widget_config.launcher;
    return /* @__PURE__ */ u(
      "button",
      {
        ref: F,
        type: "button",
        class: `launcher${f ? "" : " icon-only"}`,
        "aria-label": n.open,
        "aria-expanded": !1,
        onClick: () => o(!0),
        children: [
          /* @__PURE__ */ u(Pt, {}),
          f ? /* @__PURE__ */ u("span", { children: f }) : null
        ]
      }
    );
  }
  return /* @__PURE__ */ u("div", { class: "panel", role: "dialog", "aria-label": t.agent.name, ref: D, children: [
    /* @__PURE__ */ u("div", { class: "header", children: [
      t.agent.avatar_url ? /* @__PURE__ */ u("img", { class: "avatar", src: t.agent.avatar_url, alt: "", width: "34", height: "34" }) : /* @__PURE__ */ u("div", { class: "avatar", "aria-hidden": "true", children: t.agent.name.slice(0, 1).toUpperCase() }),
      /* @__PURE__ */ u("div", { class: "identity", children: [
        /* @__PURE__ */ u("div", { class: "name", children: t.agent.name }),
        /* @__PURE__ */ u("div", { class: "status", children: t.agent.widget_config.subtitle || n.subtitle })
      ] }),
      /* @__PURE__ */ u(
        "button",
        {
          type: "button",
          class: "icon-button",
          "aria-label": n.minimise,
          onClick: () => {
            o(!1), F.current?.focus();
          },
          children: /* @__PURE__ */ u(Mt, {})
        }
      ),
      /* @__PURE__ */ u(
        "button",
        {
          type: "button",
          class: "icon-button",
          "aria-label": n.close,
          onClick: () => {
            o(!1), F.current?.focus();
          },
          children: /* @__PURE__ */ u(It, {})
        }
      )
    ] }),
    /* @__PURE__ */ u("div", { class: "log", ref: P, role: "log", "aria-live": "polite", "aria-atomic": "false", children: [
      L.map((f) => /* @__PURE__ */ u(Dt, { message: f, labels: n, onRate: et }, f.id)),
      a ? /* @__PURE__ */ u("div", { class: "notice", children: g ? n.humanHere : n.waitingHuman }) : null,
      y ? /* @__PURE__ */ u("div", { class: "notice", children: n.captureThanks }) : null,
      M ? /* @__PURE__ */ u(
        Nt,
        {
          labels: n,
          consent: t.capture?.consent ?? null,
          onSubmit: async (f, b) => {
            const C = await r.capture({ email: f, consent: b });
            return C && w(!0), C;
          },
          onDismiss: () => $(!0)
        }
      ) : null,
      p ? /* @__PURE__ */ u("div", { class: "error", children: p }) : null
    ] }),
    t.capabilities.handoff && !a ? /* @__PURE__ */ u(
      "button",
      {
        type: "button",
        class: "handoff",
        onClick: () => {
          d(!0), r.handoff().then((f) => {
            if (!f) {
              d(!1), c(n.offline);
              return;
            }
            r.transcript().then((b) => {
              b.messages.length > 0 && h(b.messages);
            });
          });
        },
        children: n.askHuman
      }
    ) : null,
    /* @__PURE__ */ u(Lt, { labels: n, busy: s, onSend: tt }),
    t.agent.branding.show_badge ? /* @__PURE__ */ u("div", { class: "badge", children: t.agent.branding.label }) : null
  ] });
}
const jt = `
:host {
  --hvc-brand: #2B4ACB;
  --hvc-surface: #FFFFFF;
  --hvc-surface-sunken: #F5F6F8;
  --hvc-border: #E3E6EB;
  --hvc-text: #101319;
  --hvc-text-secondary: #545C6B;
  --hvc-text-tertiary: #6B7280;
  --hvc-text-inverse: #FFFFFF;
  --hvc-accent: #2B4ACB;
  --hvc-bubble-visitor: #EEF2FF;
  --hvc-shadow: 0 8px 24px rgb(16 19 25 / 0.14), 0 2px 6px rgb(16 19 25 / 0.08);
  --hvc-focus: #2B4ACB;
}

:host([data-theme='dark']) {
  --hvc-surface: #16191F;
  --hvc-surface-sunken: #0E1014;
  --hvc-border: #262B33;
  --hvc-text: #ECEEF2;
  --hvc-text-secondary: #9BA3B0;
  --hvc-text-tertiary: #868E9C;
  --hvc-text-inverse: #0E1014;
  --hvc-accent: #5A78F0;
  --hvc-bubble-visitor: rgb(90 120 240 / 0.16);
  --hvc-shadow: 0 8px 24px rgb(0 0 0 / 0.5), 0 2px 6px rgb(0 0 0 / 0.4);
  --hvc-focus: #93A8F5;
}
`, zt = `
${jt}

:host {
  --hvc-radius: 16px;
  all: initial;
  position: fixed;
  bottom: 20px;
  z-index: 2147483000;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  font-size: 15px;
  line-height: 1.5;
  color: var(--hvc-text);
}

:host([data-position='bottom-right']) { right: 20px; }
:host([data-position='bottom-left'])  { left: 20px; }

*, *::before, *::after { box-sizing: border-box; }

button {
  font: inherit;
  color: inherit;
  background: none;
  border: 0;
  margin: 0;
  cursor: pointer;
}

:focus-visible {
  outline: 2px solid var(--hvc-focus);
  outline-offset: 2px;
}

/* ---------------------------------------------------------- launcher */

.launcher {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 56px;
  min-width: 56px;
  padding: 0 18px;
  border-radius: 999px;
  background: var(--hvc-brand);
  color: #FFFFFF;
  box-shadow: var(--hvc-shadow);
  font-weight: 600;
  transition: transform 150ms ease;
}

.launcher:hover { transform: translateY(-2px); }
.launcher.icon-only { padding: 0; justify-content: center; }

/* ------------------------------------------------------------- panel */

.panel {
  display: flex;
  flex-direction: column;
  width: 380px;
  max-width: calc(100vw - 40px);
  height: 560px;
  max-height: calc(100vh - 120px);
  background: var(--hvc-surface);
  border: 1px solid var(--hvc-border);
  border-radius: var(--hvc-radius);
  box-shadow: var(--hvc-shadow);
  overflow: hidden;
}

.header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid var(--hvc-border);
  background: var(--hvc-surface);
}

.avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--hvc-brand);
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 14px;
  flex: none;
  object-fit: cover;
}

.identity { flex: 1; min-width: 0; }
.name { font-weight: 650; font-size: 15px; }
.status { font-size: 12px; color: var(--hvc-text-secondary); }

.icon-button {
  width: 44px;
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  color: var(--hvc-text-secondary);
}

.icon-button:hover { background: var(--hvc-surface-sunken); color: var(--hvc-text); }

/* ---------------------------------------------------------- messages */

.log {
  flex: 1;
  overflow-y: auto;
  padding: 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--hvc-surface);
}

.row { display: flex; }
.row.visitor { justify-content: flex-end; }

.bubble {
  max-width: 84%;
  padding: 10px 13px;
  border-radius: 14px;
  background: var(--hvc-surface-sunken);
  overflow-wrap: anywhere;
}

.row.visitor .bubble {
  background: var(--hvc-bubble-visitor);
  border-bottom-right-radius: 4px;
}

.row.clerk .bubble { border-bottom-left-radius: 4px; }

.bubble p { margin: 0 0 8px; }
.bubble p:last-child { margin-bottom: 0; }
.bubble ul, .bubble ol { margin: 0 0 8px; padding-left: 20px; }
.bubble li { margin-bottom: 2px; }
.bubble code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.9em;
  padding: 1px 4px;
  border-radius: 4px;
  background: var(--hvc-border);
}
.bubble a { color: var(--hvc-accent); text-decoration: underline; }

/* --------------------------------------------------------- citations */

.sources { margin-top: 8px; border-top: 1px solid var(--hvc-border); padding-top: 6px; }

.source {
  display: block;
  width: 100%;
  text-align: left;
  font-size: 12.5px;
  color: var(--hvc-text-secondary);
  padding: 4px 0;
  text-decoration: none;
}

.source:hover { color: var(--hvc-accent); }
.source .caret { color: var(--hvc-accent); margin-right: 4px; }

.feedback { display: flex; gap: 4px; margin-top: 6px; }

.feedback button {
  font-size: 12px;
  color: var(--hvc-text-tertiary);
  padding: 4px 6px;
  border-radius: 6px;
  min-height: 28px;
}

.feedback button:hover { background: var(--hvc-surface-sunken); color: var(--hvc-text); }
.feedback button[aria-pressed='true'] { color: var(--hvc-accent); }
.feedback .note { color: var(--hvc-text-tertiary); padding: 4px 6px; font-size: 12px; }

/* ----------------------------------------------------------- typing */

.typing { display: flex; gap: 4px; padding: 4px 2px; }

.typing span {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--hvc-text-tertiary);
  animation: pulse 1.2s infinite ease-in-out;
}

.typing span:nth-child(2) { animation-delay: 0.15s; }
.typing span:nth-child(3) { animation-delay: 0.3s; }

@keyframes pulse {
  0%, 60%, 100% { opacity: 0.3; }
  30% { opacity: 1; }
}

/* --------------------------------------------------------- composer */

.composer {
  border-top: 1px solid var(--hvc-border);
  padding: 10px 12px;
  background: var(--hvc-surface);
}

.field {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  border: 1px solid var(--hvc-border);
  border-radius: 12px;
  padding: 6px 6px 6px 12px;
  background: var(--hvc-surface);
}

.field:focus-within { border-color: var(--hvc-accent); }

.field textarea {
  flex: 1;
  border: 0;
  outline: none;
  resize: none;
  background: transparent;
  color: var(--hvc-text);
  font: inherit;
  max-height: 96px;
  padding: 6px 0;
}

.field textarea::placeholder { color: var(--hvc-text-tertiary); }

.send {
  width: 44px;
  height: 44px;
  flex: none;
  border-radius: 10px;
  background: var(--hvc-brand);
  color: #FFFFFF;
  display: flex;
  align-items: center;
  justify-content: center;
}

.send[disabled] { opacity: 0.4; cursor: not-allowed; }

.badge {
  text-align: center;
  font-size: 11px;
  color: var(--hvc-text-tertiary);
  padding-top: 8px;
}

.error {
  font-size: 12.5px;
  color: var(--hvc-text-secondary);
  padding: 6px 2px 0;
}

/* A person has this conversation now. Styled as a note rather than as a
   message, because nobody wrote it — it is the state of the room. */
.notice {
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--hvc-text-secondary);
  background: var(--hvc-surface-sunken);
  border-radius: 10px;
  padding: 8px 10px;
  margin-top: 4px;
}

.from-human {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--hvc-text-tertiary);
  margin-bottom: 4px;
}

.handoff {
  align-self: flex-start;
  margin: 0 14px 4px;
  padding: 5px 10px;
  font: inherit;
  font-size: 12.5px;
  color: var(--hvc-text-secondary);
  background: transparent;
  border: 1px solid var(--hvc-border);
  border-radius: 999px;
  cursor: pointer;
}

.handoff:hover { color: var(--hvc-text); border-color: var(--hvc-text-tertiary); }
.handoff:focus-visible { outline: 2px solid var(--hvc-brand); outline-offset: 2px; }

/* The capture card sits inside the transcript rather than over it. A
   modal would interrupt a conversation the visitor is in the middle of,
   which is exactly when this appears. */
.capture {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  margin-top: 4px;
  background: var(--hvc-surface-sunken);
  border: 1px solid var(--hvc-border);
  border-radius: 12px;
}

.capture-title {
  font-size: 13px;
  line-height: 1.45;
  color: var(--hvc-text);
}

.capture-input {
  font: inherit;
  font-size: 13.5px;
  color: var(--hvc-text);
  background: var(--hvc-surface);
  border: 1px solid var(--hvc-border);
  border-radius: 8px;
  padding: 8px 10px;
  width: 100%;
  box-sizing: border-box;
}

.capture-input:focus-visible {
  outline: 2px solid var(--hvc-brand);
  outline-offset: 1px;
}

.capture-consent {
  display: flex;
  gap: 7px;
  align-items: flex-start;
  font-size: 12px;
  line-height: 1.45;
  color: var(--hvc-text-secondary);
}

.capture-error {
  font-size: 12px;
  color: var(--hvc-text-secondary);
}

.capture-actions {
  display: flex;
  gap: 8px;
}

/* Both actions are real buttons at the same weight. "Not now" rendered
   as a faint link is the pattern that makes a dismissal hard to find. */
.capture-send,
.capture-dismiss {
  font: inherit;
  font-size: 13px;
  padding: 7px 12px;
  border-radius: 8px;
  cursor: pointer;
  border: 1px solid transparent;
}

.capture-send {
  /* The one shape in the card that carries white text on the customer's
     own colour, exactly like the send button and the launcher. */
  color: #FFFFFF;
  background: var(--hvc-brand);
}

.capture-dismiss {
  color: var(--hvc-text-secondary);
  background: transparent;
  border-color: var(--hvc-border);
}

.capture-send:focus-visible,
.capture-dismiss:focus-visible {
  outline: 2px solid var(--hvc-brand);
  outline-offset: 2px;
}

.capture-send[disabled],
.capture-dismiss[disabled] { opacity: 0.6; cursor: default; }

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}

@media (prefers-reduced-motion: reduce) {
  * { animation: none !important; transition: none !important; }
}

@media (max-width: 480px) {
  .panel {
    width: calc(100vw - 24px);
    height: calc(100vh - 100px);
  }
}
`, Oe = "hvc-widget-root";
function Be() {
  const t = window.HVC_WIDGET;
  if (!t?.agent || document.getElementById(Oe))
    return;
  const e = document.createElement("div");
  e.id = Oe, e.setAttribute("data-position", t.agent.widget_config.position), e.setAttribute("data-theme", Wt(t.agent.widget_config.theme));
  const n = e.attachShadow({ mode: "open" }), r = document.createElement("style");
  r.textContent = zt, n.appendChild(r);
  const i = t.agent.widget_config.accent;
  e.style.setProperty("--hvc-brand", i), e.style.setProperty("--hvc-radius", `${t.agent.widget_config.radius}px`);
  const o = document.createElement("div");
  n.appendChild(o), document.body.appendChild(e), ut(We(Ut, { boot: t, host: e }), o), t.agent.widget_config.theme === "auto" && window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").addEventListener(
    "change",
    (s) => e.setAttribute("data-theme", s.matches ? "dark" : "light")
  );
}
function Wt(t) {
  return t !== "auto" ? t : window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}
document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", Be, { once: !0 }) : Be();
