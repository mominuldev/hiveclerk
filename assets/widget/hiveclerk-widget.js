var qe = Object.defineProperty;
var Xe = (t, e, n) => e in t ? qe(t, e, { enumerable: !0, configurable: !0, writable: !0, value: n }) : t[e] = n;
var de = (t, e, n) => Xe(t, typeof e != "symbol" ? e + "" : e, n);
var ee, g, Pe, D, he, Me, Ne, ne, K, L, Ie, se, oe, ie, G = {}, Q = [], Ye = /acit|ex(?:s|g|n|p|$)|rph|grid|ows|mnc|ntw|ine[ch]|zoo|^ord|itera/i, te = Array.isArray;
function A(t, e) {
  for (var n in e) t[n] = e[n];
  return t;
}
function ce(t) {
  t && t.parentNode && t.parentNode.removeChild(t);
}
function Oe(t, e, n) {
  var r, i, o, s = {};
  for (o in e) o == "key" ? r = e[o] : o == "ref" ? i = e[o] : s[o] = e[o];
  if (arguments.length > 2 && (s.children = arguments.length > 3 ? ee.call(arguments, 2) : n), typeof t == "function" && t.defaultProps != null) for (o in t.defaultProps) s[o] === void 0 && (s[o] = t.defaultProps[o]);
  return q(t, s, r, i, null);
}
function q(t, e, n, r, i) {
  var o = { type: t, props: e, key: n, ref: r, __k: null, __: null, __b: 0, __e: null, __c: null, constructor: void 0, __v: i ?? ++Pe, __i: -1, __u: 0 };
  return i == null && g.vnode != null && g.vnode(o), o;
}
function z(t) {
  return t.children;
}
function X(t, e) {
  this.props = t, this.context = e;
}
function M(t, e) {
  if (e == null) return t.__ ? M(t.__, t.__i + 1) : null;
  for (var n; e < t.__k.length; e++) if ((n = t.__k[e]) != null && n.__e != null) return n.__e;
  return typeof t.type == "function" ? M(t) : null;
}
function Ge(t) {
  if (t.__P && t.__d) {
    var e = t.__v, n = e.__e, r = [], i = [], o = A({}, e);
    o.__v = e.__v + 1, g.vnode && g.vnode(o), le(t.__P, o, e, t.__n, t.__P.namespaceURI, 32 & e.__u ? [n] : null, r, n ?? M(e), !!(32 & e.__u), i), o.__v = e.__v, o.__.__k[o.__i] = o, Re(r, o, i), e.__e = e.__ = null, o.__e != n && Be(o);
  }
}
function Be(t) {
  if ((t = t.__) != null && t.__c != null) return t.__e = t.__c.base = null, t.__k.some(function(e) {
    if (e != null && e.__e != null) return t.__e = t.__c.base = e.__e;
  }), Be(t);
}
function pe(t) {
  (!t.__d && (t.__d = !0) && D.push(t) && !Z.__r++ || he != g.debounceRendering) && ((he = g.debounceRendering) || Me)(Z);
}
function Z() {
  try {
    for (var t, e = 1; D.length; ) D.length > e && D.sort(Ne), t = D.shift(), e = D.length, Ge(t);
  } finally {
    D.length = Z.__r = 0;
  }
}
function Ue(t, e, n, r, i, o, s, l, d, c, h) {
  var p, a, u, m, T, k, y = r && r.__k || Q, v = e.length;
  for (d = Qe(n, e, y, d, v), p = 0; p < v; p++) (u = n.__k[p]) != null && (a = u.__i != -1 && y[u.__i] || G, u.__i = p, k = le(t, u, a, i, o, s, l, d, c, h), m = u.__e, u.ref && a.ref != u.ref && (a.ref && _e(a.ref, null, u), h.push(u.ref, u.__c || m, u)), T == null && m != null && (T = m), 4 & u.__u ? (d = Le(u, d, t), a.__e && (a.__e = null)) : typeof u.type == "function" && k !== void 0 ? d = k : m && (d = m.nextSibling), u.__u &= -7);
  return n.__e = T, d;
}
function Qe(t, e, n, r, i) {
  var o, s, l, d, c, h = n.length, p = h, a = 0;
  for (t.__k = new Array(i), o = 0; o < i; o++) (s = e[o]) != null && typeof s != "boolean" && typeof s != "function" ? (typeof s == "string" || typeof s == "number" || typeof s == "bigint" || s.constructor == String ? s = t.__k[o] = q(null, s, null, null, null) : te(s) ? s = t.__k[o] = q(z, { children: s }, null, null, null) : s.constructor === void 0 && s.__b > 0 ? s = t.__k[o] = q(s.type, s.props, s.key, s.ref ? s.ref : null, s.__v) : t.__k[o] = s, d = o + a, s.__ = t, s.__b = t.__b + 1, l = null, (c = s.__i = Ze(s, n, d, p)) != -1 && (p--, (l = n[c]) && (l.__u |= 2)), l == null || l.__v == null ? (c == -1 && (i > h ? a-- : i < h && a++), typeof s.type != "function" && (s.__u |= 4)) : c != d && (c == d - 1 ? a-- : c == d + 1 ? a++ : (c > d ? a-- : a++, s.__u |= 4))) : t.__k[o] = null;
  if (p) for (o = 0; o < h; o++) (l = n[o]) != null && (2 & l.__u) == 0 && (l.__e == r && (r = M(l)), ze(l, l));
  return r;
}
function Le(t, e, n) {
  var r, i;
  if (typeof t.type == "function") {
    for (r = t.__k, i = 0; r && i < r.length; i++) r[i] && (r[i].__ = t, e = Le(r[i], e, n));
    return e;
  }
  t.__e != e && (e && t.type && !e.parentNode && (e = M(t)), e = n.insertBefore(t.__e, e || null));
  do
    e = e && e.nextSibling;
  while (e != null && e.nodeType == 8);
  return e;
}
function Ze(t, e, n, r) {
  var i, o, s, l = t.key, d = t.type, c = e[n], h = c != null && (2 & c.__u) == 0;
  if (c === null && l == null || h && l == c.key && d == c.type) return n;
  if (r > (h ? 1 : 0)) {
    for (i = n - 1, o = n + 1; i >= 0 || o < e.length; ) if ((c = e[s = i >= 0 ? i-- : o++]) != null && (2 & c.__u) == 0 && l == c.key && d == c.type) return s;
  }
  return -1;
}
function fe(t, e, n) {
  e[0] == "-" ? t.setProperty(e, n ?? "") : t[e] = n == null ? "" : typeof n != "number" || Ye.test(e) ? n : n + "px";
}
function J(t, e, n, r, i) {
  var o, s;
  e: if (e == "style") if (typeof n == "string") t.style.cssText = n;
  else {
    if (typeof r == "string" && (t.style.cssText = r = ""), r) for (e in r) n && e in n || fe(t.style, e, "");
    if (n) for (e in n) r && n[e] == r[e] || fe(t.style, e, n[e]);
  }
  else if (e[0] == "o" && e[1] == "n") o = e != (e = e.replace(Ie, "$1")), s = e.toLowerCase(), e = s in t || e == "onFocusOut" || e == "onFocusIn" ? s.slice(2) : e.slice(2), t.l || (t.l = {}), t.l[e + o] = n, n ? r ? n[L] = r[L] : (n[L] = se, t.addEventListener(e, o ? ie : oe, o)) : t.removeEventListener(e, o ? ie : oe, o);
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
function ve(t) {
  return function(e) {
    if (this.l) {
      var n = this.l[e.type + t];
      if (e[K] == null) e[K] = se++;
      else if (e[K] < n[L]) return;
      return n(g.event ? g.event(e) : e);
    }
  };
}
function le(t, e, n, r, i, o, s, l, d, c) {
  var h, p, a, u, m, T, k, y, v, H, E, F, P, V, f, b, x = e.type;
  if (e.constructor !== void 0) return null;
  128 & n.__u && (d = !!(32 & n.__u), o = [l = e.__e = n.__e]), (h = g.__b) && h(e);
  e: if (typeof x == "function") {
    p = s.length;
    try {
      if (v = e.props, H = x.prototype && x.prototype.render, E = (h = x.contextType) && r[h.__c], F = h ? E ? E.props.value : h.__ : r, n.__c ? y = (a = e.__c = n.__c).__ = a.__E : (H ? e.__c = a = new x(v, F) : (e.__c = a = new X(v, F), a.constructor = x, a.render = tt), E && E.sub(a), a.state || (a.state = {}), a.__n = r, u = a.__d = !0, a.__h = [], a._sb = []), H && a.__s == null && (a.__s = a.state), H && x.getDerivedStateFromProps != null && (a.__s == a.state && (a.__s = A({}, a.__s)), A(a.__s, x.getDerivedStateFromProps(v, a.__s))), m = a.props, T = a.state, a.__v = e, u) H && x.getDerivedStateFromProps == null && a.componentWillMount != null && a.componentWillMount(), H && a.componentDidMount != null && a.__h.push(a.componentDidMount);
      else {
        if (H && x.getDerivedStateFromProps == null && v !== m && a.componentWillReceiveProps != null && a.componentWillReceiveProps(v, F), e.__v == n.__v || !a.__e && a.shouldComponentUpdate != null && a.shouldComponentUpdate(v, a.__s, F) === !1) {
          e.__v != n.__v && (a.props = v, a.state = a.__s, a.__d = !1), e.__e = n.__e, e.__k = n.__k, e.__k.some(function($) {
            $ && ($.__ = e);
          }), Q.push.apply(a.__h, a._sb), a._sb = [], a.__h.length && s.push(a), l = M(n);
          break e;
        }
        a.componentWillUpdate != null && a.componentWillUpdate(v, a.__s, F), H && a.componentDidUpdate != null && a.__h.push(function() {
          a.componentDidUpdate(m, T, k);
        });
      }
      if (a.context = F, a.props = v, a.__P = t, a.__e = !1, P = g.__r, V = 0, H) a.state = a.__s, a.__d = !1, P && P(e), h = a.render(a.props, a.state, a.context), Q.push.apply(a.__h, a._sb), a._sb = [];
      else do
        a.__d = !1, P && P(e), h = a.render(a.props, a.state, a.context), a.state = a.__s;
      while (a.__d && ++V < 25);
      a.state = a.__s, a.getChildContext != null && (r = A(A({}, r), a.getChildContext())), H && !u && a.getSnapshotBeforeUpdate != null && (k = a.getSnapshotBeforeUpdate(m, T)), f = h != null && h.type === z && h.key == null ? We(h.props.children) : h, l = Ue(t, te(f) ? f : [f], e, n, r, i, o, s, l, d, c), a.base = e.__e, e.__u &= -161, a.__h.length && s.push(a), y && (a.__E = a.__ = null);
    } catch ($) {
      if (s.length = p, e.__v = null, d || o != null) {
        if ($.then) {
          for (e.__u |= d ? 160 : 128; l && l.nodeType == 8 && l.nextSibling; ) l = l.nextSibling;
          o != null && (o[o.indexOf(l)] = null), e.__e = l;
        } else if (o != null) for (b = o.length; b--; ) ce(o[b]);
      } else e.__e = n.__e;
      e.__k == null && (e.__k = n.__k || []), $.then || je(e), g.__e($, e, n);
    }
  } else o == null && e.__v == n.__v ? (e.__k = n.__k, e.__e = n.__e) : l = e.__e = et(n.__e, e, n, r, i, o, s, d, c);
  return (h = g.diffed) && h(e), 128 & e.__u ? void 0 : l;
}
function je(t) {
  t && (t.__c && (t.__c.__e = !0), t.__k && t.__k.some(je));
}
function Re(t, e, n) {
  for (var r = 0; r < n.length; r++) _e(n[r], n[++r], n[++r]);
  g.__c && g.__c(e, t), t.some(function(i) {
    try {
      t = i.__h, i.__h = [], t.some(function(o) {
        o.call(i);
      });
    } catch (o) {
      g.__e(o, i.__v);
    }
  });
}
function We(t) {
  return typeof t != "object" || t == null || t.__b > 0 ? t : te(t) ? t.map(We) : t.constructor !== void 0 ? null : A({}, t);
}
function et(t, e, n, r, i, o, s, l, d) {
  var c, h, p, a, u, m, T, k = n.props || G, y = e.props, v = e.type;
  if (v == "svg" ? i = "http://www.w3.org/2000/svg" : v == "math" ? i = "http://www.w3.org/1998/Math/MathML" : i || (i = "http://www.w3.org/1999/xhtml"), o != null) {
    for (c = 0; c < o.length; c++) if ((u = o[c]) && "setAttribute" in u == !!v && (v ? u.localName == v : u.nodeType == 3)) {
      t = u, o[c] = null;
      break;
    }
  }
  if (t == null) {
    if (v == null) return document.createTextNode(y);
    t = document.createElementNS(i, v, y.is && y), l && (g.__m && g.__m(e, o), l = !1), o = null;
  }
  if (v == null) k === y || l && t.data == y || (t.data = y);
  else {
    if (o = v == "textarea" && y.defaultValue != null ? null : o && ee.call(t.childNodes), !l && o != null) for (k = {}, c = 0; c < t.attributes.length; c++) k[(u = t.attributes[c]).name] = u.value;
    for (c in k) u = k[c], c == "dangerouslySetInnerHTML" ? p = u : c == "children" || c in y || c == "value" && "defaultValue" in y || c == "checked" && "defaultChecked" in y || J(t, c, null, u, i);
    for (c in y) u = y[c], c == "children" ? a = u : c == "dangerouslySetInnerHTML" ? h = u : c == "value" ? m = u : c == "checked" ? T = u : l && typeof u != "function" || k[c] === u || J(t, c, u, k[c], i);
    if (h) l || p && (h.__html == p.__html || h.__html == t.innerHTML) || (t.innerHTML = h.__html), e.__k = [];
    else if (p && (t.innerHTML = ""), Ue(e.type == "template" ? t.content : t, te(a) ? a : [a], e, n, r, v == "foreignObject" ? "http://www.w3.org/1999/xhtml" : i, o, s, o ? o[0] : n.__k && M(n, 0), l, d), o != null) for (c = o.length; c--; ) ce(o[c]);
    l && v != "textarea" || (c = "value", v == "progress" && m == null ? t.removeAttribute("value") : m != null && (m !== t[c] || v == "progress" && !m || v == "option" && m != k[c]) && J(t, c, m, k[c], i), c = "checked", T != null && T != t[c] && J(t, c, T, k[c], i));
  }
  return t;
}
function _e(t, e, n) {
  try {
    if (typeof t == "function") {
      var r = typeof t.__u == "function";
      r && t.__u(), r && e == null || (t.__u = t(e));
    } else t.current = e;
  } catch (i) {
    g.__e(i, n);
  }
}
function ze(t, e, n) {
  var r, i;
  if (g.unmount && g.unmount(t), (r = t.ref) && (r.current && r.current != t.__e || _e(r, null, e)), (r = t.__c) != null) {
    if (r.componentWillUnmount) try {
      r.componentWillUnmount();
    } catch (o) {
      g.__e(o, e);
    }
    r.base = r.__P = r.__n = null;
  }
  if (r = t.__k) for (i = 0; i < r.length; i++) r[i] && ze(r[i], e, n || typeof t.type != "function");
  n || ce(t.__e), t.__c = t.__ = t.__e = void 0;
}
function tt(t, e, n) {
  return this.constructor(t, n);
}
function nt(t, e, n) {
  var r, i, o, s;
  e == document && (e = document.documentElement), g.__ && g.__(t, e), i = (r = !1) ? null : e.__k, o = [], s = [], le(e, t = e.__k = Oe(z, null, [t]), i || G, G, e.namespaceURI, i ? null : e.firstChild ? ee.call(e.childNodes) : null, o, i ? i.__e : e.firstChild, r, s), Re(o, t, s), t.props.children = null;
}
ee = Q.slice, g = { __e: function(t, e, n, r) {
  for (var i, o, s; e = e.__; ) if ((i = e.__c) && !i.__) try {
    if ((o = i.constructor) && o.getDerivedStateFromError != null && (i.setState(o.getDerivedStateFromError(t)), s = i.__d), i.componentDidCatch != null && (i.componentDidCatch(t, r || {}), s = i.__d), s) return i.__E = i;
  } catch (l) {
    t = l;
  }
  throw t;
} }, Pe = 0, X.prototype.setState = function(t, e) {
  var n;
  n = this.__s != null && this.__s != this.state ? this.__s : this.__s = A({}, this.state), typeof t == "function" && (t = t(A({}, n), this.props)), t && A(n, t), t != null && this.__v && (e && this._sb.push(e), pe(this));
}, X.prototype.forceUpdate = function(t) {
  this.__v && (this.__e = !0, t && this.__h.push(t), pe(this));
}, X.prototype.render = z, D = [], Me = typeof Promise == "function" ? Promise.prototype.then.bind(Promise.resolve()) : setTimeout, Ne = function(t, e) {
  return t.__v.__b - e.__v.__b;
}, Z.__r = 0, ne = Math.random().toString(8), K = "__d" + ne, L = "__a" + ne, Ie = /(PointerCapture)$|Capture$/i, se = 0, oe = ve(!1), ie = ve(!0);
var rt = 0;
function _(t, e, n, r, i, o) {
  e || (e = {});
  var s, l, d = e;
  if ("ref" in d) for (l in d = {}, e) l == "ref" ? s = e[l] : d[l] = e[l];
  var c = { type: t, props: d, key: n, ref: s, __k: null, __: null, __b: 0, __e: null, __c: null, constructor: void 0, __v: --rt, __i: -1, __u: 0, __source: i, __self: o };
  if (typeof t == "function" && (s = t.defaultProps)) for (l in s) d[l] === void 0 && (d[l] = s[l]);
  return g.vnode && g.vnode(c), c;
}
var R, w, re, ge, W = 0, Ve = [], S = g, me = S.__b, be = S.__r, xe = S.diffed, ye = S.__c, we = S.unmount, ke = S.__;
function ue(t, e) {
  S.__h && S.__h(w, t, W || e), W = 0;
  var n = w.__H || (w.__H = { __: [], __h: [] });
  return t >= n.__.length && n.__.push({}), n.__[t];
}
function I(t) {
  return W = 1, ot(Ke, t);
}
function ot(t, e, n) {
  var r = ue(R++, 2);
  if (r.t = t, !r.__c && (r.__ = [Ke(void 0, e), function(l) {
    var d = r.__N ? r.__N[0] : r.__[0], c = r.t(d, l);
    d !== c && (r.__N = [c, r.__[1]], r.__c.setState({}));
  }], r.__c = w, !w.__f)) {
    var i = function(l, d, c) {
      if (!r.__c.__H) return !0;
      var h = !1, p = r.__c.props !== l;
      if (r.__c.__H.__.some(function(u) {
        if (u.__N) {
          h = !0;
          var m = u.__[0];
          u.__ = u.__N, u.__N = void 0, m !== u.__[0] && (p = !0);
        }
      }), o) {
        var a = o.call(this, l, d, c);
        return h ? a || p : a;
      }
      return !h || p;
    };
    w.__f = !0;
    var o = w.shouldComponentUpdate, s = w.componentWillUpdate;
    w.componentWillUpdate = function(l, d, c) {
      if (this.__e) {
        var h = o;
        o = void 0, i(l, d, c), o = h;
      }
      s && s.call(this, l, d, c);
    }, w.shouldComponentUpdate = i;
  }
  return r.__N || r.__;
}
function B(t, e) {
  var n = ue(R++, 3);
  !S.__s && Je(n.__H, e) && (n.__ = t, n.u = e, w.__H.__h.push(n));
}
function U(t) {
  return W = 5, j(function() {
    return { current: t };
  }, []);
}
function j(t, e) {
  var n = ue(R++, 7);
  return Je(n.__H, e) && (n.__ = t(), n.__H = e, n.__h = t), n.__;
}
function Se(t, e) {
  return W = 8, j(function() {
    return t;
  }, e);
}
function it() {
  for (var t; t = Ve.shift(); ) {
    var e = t.__H;
    if (t.__P && e) try {
      e.__h.some(Y), e.__h.some(ae), e.__h = [];
    } catch (n) {
      e.__h = [], S.__e(n, t.__v);
    }
  }
}
S.__b = function(t) {
  w = null, me && me(t);
}, S.__ = function(t, e) {
  t && e.__k && e.__k.__m && (t.__m = e.__k.__m), ke && ke(t, e);
}, S.__r = function(t) {
  be && be(t), R = 0;
  var e = (w = t.__c).__H;
  e && (re === w ? (e.__h = [], w.__h = [], e.__.some(function(n) {
    n.__N && (n.__ = n.__N), n.u = n.__N = void 0;
  })) : (e.__h.some(Y), e.__h.some(ae), e.__h = [], R = 0)), re = w;
}, S.diffed = function(t) {
  xe && xe(t);
  var e = t.__c;
  e && e.__H && (e.__H.__h.length && (Ve.push(e) !== 1 && ge === S.requestAnimationFrame || ((ge = S.requestAnimationFrame) || at)(it)), e.__H.__.some(function(n) {
    n.u && (n.__H = n.u, n.u = void 0);
  })), re = w = null;
}, S.__c = function(t, e) {
  e.some(function(n) {
    try {
      n.__h.some(Y), n.__h = n.__h.filter(function(r) {
        return !r.__ || ae(r);
      });
    } catch (r) {
      e.some(function(i) {
        i.__h && (i.__h = []);
      }), e = [], S.__e(r, n.__v);
    }
  }), ye && ye(t, e);
}, S.unmount = function(t) {
  we && we(t);
  var e, n = t.__c;
  n && n.__H && (n.__H.__.some(function(r) {
    try {
      Y(r);
    } catch (i) {
      e = i;
    }
  }), n.__H = void 0, e && S.__e(e, n.__v));
};
var Ce = typeof requestAnimationFrame == "function";
function at(t) {
  var e, n = function() {
    clearTimeout(r), Ce && cancelAnimationFrame(e), setTimeout(t);
  }, r = setTimeout(n, 35);
  Ce && (e = requestAnimationFrame(n));
}
function Y(t) {
  var e = w, n = t.__c;
  typeof n == "function" && (t.__c = void 0, n()), w = e;
}
function ae(t) {
  var e = w;
  t.__c = t.__(), w = e;
}
function Je(t, e) {
  return !t || t.length !== e.length || e.some(function(n, r) {
    return n !== t[r];
  });
}
function Ke(t, e) {
  return typeof e == "function" ? e(t) : e;
}
const st = "hvc.session", $e = "hvc.transport";
function Te(t) {
  try {
    return window.sessionStorage.getItem(t);
  } catch {
    return null;
  }
}
function He(t, e) {
  try {
    window.sessionStorage.setItem(t, e);
  } catch {
  }
}
class ct {
  constructor(e) {
    de(this, "session", null);
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
        language: navigator.language
      })
    });
    if (!e.ok)
      throw new Error(`session ${e.status}`);
    const n = await e.json(), r = {
      token: n.data.session,
      conversation: n.data.conversation,
      expires: n.data.expires_at ? Date.parse(n.data.expires_at) : Date.now() + 36e5
    };
    return this.session = r, He(this.key(), JSON.stringify(r)), r.token;
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
    const e = Te($e);
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
    He($e, e);
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
    return `${st}.${this.boot.agent.uuid}`;
  }
  restore() {
    const e = Te(this.key());
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
const lt = 2500, _t = 260, ut = 9e4;
async function dt(t, e, n, r) {
  return n === "poll" ? (await Fe(t, e, r), "poll") : await ht(t, e, r) ? "sse" : (await Fe(t, e, r), "poll");
}
async function ht(t, e, n) {
  const r = await t.token(), i = new AbortController();
  let o = !1;
  const s = window.setTimeout(() => {
    o || i.abort();
  }, lt);
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
    const d = l.body.getReader(), c = new TextDecoder();
    let h = "";
    for (; ; ) {
      const { done: p, value: a } = await d.read();
      if (p)
        break;
      o || (o = !0, window.clearTimeout(s)), h += c.decode(a, { stream: !0 });
      const u = h.split(`

`);
      h = u.pop() ?? "";
      for (const m of u)
        pt(m, n);
    }
    return window.clearTimeout(s), o;
  } catch {
    return window.clearTimeout(s), !1;
  }
}
function pt(t, e) {
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
async function Fe(t, e, n) {
  const r = await t.token(), i = vt();
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
  const l = Date.now() + ut;
  let d = 0;
  for (; ; ) {
    if (Date.now() > l) {
      n.onError("timeout");
      return;
    }
    await ft(_t);
    const c = await fetch(
      t.url(`/public/chat/poll?message=${i}&cursor=${d}`),
      { headers: { "X-HVC-Session": r } }
    );
    if (c.status === 401) {
      t.forget(), n.onError("expired");
      return;
    }
    if (!c.ok)
      continue;
    const p = (await c.json()).data;
    if (!p.pending && (o || (o = !0, n.onStart(p.message_id ?? "")), p.replaced ? n.onReplace(p.text) : p.text && n.onDelta(p.text), d = p.cursor, p.complete)) {
      if (p.error) {
        n.onError(p.error.message);
        return;
      }
      p.citations?.length && n.onCitations(p.citations), n.onDone(p.done);
      return;
    }
  }
}
function ft(t) {
  return new Promise((e) => window.setTimeout(e, t));
}
function vt() {
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
const gt = {
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
  fromHuman: "From a colleague"
};
function mt(t) {
  return gt;
}
const bt = /(`[^`]+`)|(\*\*[^*]+\*\*)|(__[^_]+__)|(\*[^*\n]+\*)|(\[[^\]]+\]\([^)\s]+\))/;
function xt(t) {
  const e = [], n = t.split(`
`);
  let r = [], i = null, o = 0;
  const s = () => {
    r.length && (e.push(/* @__PURE__ */ _("p", { children: Ee(r.join(" ")) }, `p${o++}`)), r = []);
  }, l = () => {
    if (!i)
      return;
    const d = i.items.map((c, h) => /* @__PURE__ */ _("li", { children: Ee(c) }, `li${h}`));
    e.push(
      i.ordered ? /* @__PURE__ */ _("ol", { children: d }, `l${o++}`) : /* @__PURE__ */ _("ul", { children: d }, `l${o++}`)
    ), i = null;
  };
  for (const d of n) {
    const c = d.trimEnd();
    if (!c.trim()) {
      s(), l();
      continue;
    }
    const h = /^\s*[-*+]\s+(.*)$/.exec(c), p = /^\s*\d+[.)]\s+(.*)$/.exec(c);
    if (h || p) {
      s();
      const a = !!p, u = h?.[1] ?? p?.[1];
      (!i || i.ordered !== a) && (l(), i = { ordered: a, items: [] }), i.items.push(u);
      continue;
    }
    l(), r.push(c.trim());
  }
  return s(), l(), e;
}
function Ee(t) {
  const e = [];
  let n = t, r = 0;
  for (; ; ) {
    const i = bt.exec(n);
    if (!i || i.index === void 0)
      break;
    i.index > 0 && e.push(n.slice(0, i.index));
    const o = i[0];
    e.push(yt(o, r++)), n = n.slice(i.index + o.length);
  }
  return n && e.push(n), e;
}
function yt(t, e) {
  if (t.startsWith("`"))
    return /* @__PURE__ */ _("code", { children: t.slice(1, -1) }, e);
  if (t.startsWith("**") || t.startsWith("__"))
    return /* @__PURE__ */ _("strong", { children: t.slice(2, -2) }, e);
  if (t.startsWith("*"))
    return /* @__PURE__ */ _("em", { children: t.slice(1, -1) }, e);
  const n = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(t);
  if (n) {
    const r = wt(n[2] ?? "");
    return r ? /* @__PURE__ */ _("a", { href: r, target: "_blank", rel: "noopener noreferrer nofollow", children: n[1] }, e) : n[1] ?? t;
  }
  return t;
}
function wt(t) {
  const e = t.trim();
  return e.startsWith("/") || e.startsWith("#") || /^https?:\/\//i.test(e) ? e : null;
}
function kt({ message: t, labels: e, onRate: n }) {
  const r = t.role === "clerk", i = !!t.streaming && t.text === "";
  return /* @__PURE__ */ _("div", { class: `row ${t.role}`, children: /* @__PURE__ */ _("div", { class: "bubble", children: [
    t.fromHuman ? /* @__PURE__ */ _("div", { class: "from-human", children: e.fromHuman }) : null,
    i ? /* @__PURE__ */ _("div", { class: "typing", "aria-label": e.thinking, children: [
      /* @__PURE__ */ _("span", {}),
      /* @__PURE__ */ _("span", {}),
      /* @__PURE__ */ _("span", {})
    ] }) : xt(t.text),
    r && t.citations.length > 0 && /* @__PURE__ */ _("div", { class: "sources", children: [
      /* @__PURE__ */ _("span", { class: "sr-only", children: e.sources }),
      t.citations.map(
        (o) => o.url ? /* @__PURE__ */ _(
          "a",
          {
            class: "source",
            href: o.url,
            target: "_blank",
            rel: "noopener noreferrer",
            children: [
              /* @__PURE__ */ _("span", { class: "caret", "aria-hidden": "true", children: "▸" }),
              o.heading_path || o.title
            ]
          },
          o.url + o.heading_path
        ) : /* @__PURE__ */ _("span", { class: "source", children: [
          /* @__PURE__ */ _("span", { class: "caret", "aria-hidden": "true", children: "▸" }),
          o.heading_path || o.title
        ] }, o.title + o.heading_path)
      )
    ] }),
    r && !t.fromHuman && !t.streaming && t.text !== "" && /* @__PURE__ */ _("div", { class: "feedback", children: t.rating ? /* @__PURE__ */ _("span", { class: "note", children: e.rated }) : /* @__PURE__ */ _(z, { children: [
      /* @__PURE__ */ _("button", { type: "button", "aria-label": e.helpful, onClick: () => n(t.id, 1), children: "▲" }),
      /* @__PURE__ */ _(
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
function St() {
  return /* @__PURE__ */ _("svg", { width: "22", height: "22", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ _(
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
function Ct() {
  return /* @__PURE__ */ _("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ _("path", { d: "m6 6 12 12M18 6 6 18", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round" }) });
}
function $t() {
  return /* @__PURE__ */ _("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ _("path", { d: "M6 12h12", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round" }) });
}
function Tt() {
  return /* @__PURE__ */ _("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ _(
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
const Ht = 2e3;
function Ft({ labels: t, busy: e, onSend: n }) {
  const r = U(null), i = () => {
    const o = r.current;
    if (!o)
      return;
    const s = o.value.trim();
    !s || e || (n(s), o.value = "", o.style.height = "auto");
  };
  return /* @__PURE__ */ _("div", { class: "composer", children: /* @__PURE__ */ _("div", { class: "field", children: [
    /* @__PURE__ */ _(
      "textarea",
      {
        ref: r,
        rows: 1,
        maxLength: Ht,
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
    /* @__PURE__ */ _(
      "button",
      {
        type: "button",
        class: "send",
        disabled: e,
        "aria-label": e ? t.sending : t.send,
        onClick: i,
        children: /* @__PURE__ */ _(Tt, {})
      }
    )
  ] }) });
}
function Et({ boot: t, host: e }) {
  const n = j(() => mt(t.agent.locale), [t.agent.locale]), r = j(() => new ct(t), [t]), [i, o] = I(!1), [s, l] = I(!1), [d, c] = I(null), [h, p] = I([]), [a, u] = I(!1), [m, T] = I(!1), k = U(null), y = U(null), v = U(null), H = U(r.transport() ?? "sse"), E = t.agent.greeting?.trim(), F = j(() => !E || h.length > 0 ? h : [{ id: "greeting", role: "clerk", text: E, citations: [], rating: null }], [E, h]);
  B(() => {
    !i || h.length > 0 || !r.conversation() || r.transcript().then((f) => {
      f.messages.length > 0 && p(f.messages), u(f.awaitingHuman), T(f.humanActive);
    });
  }, [i, h.length, r]), B(() => {
    if (!i || !a)
      return;
    const f = window.setInterval(() => {
      r.transcript().then((b) => {
        b.messages.length > 0 && p(b.messages), u(b.awaitingHuman), T(b.humanActive);
      });
    }, 8e3);
    return () => window.clearInterval(f);
  }, [i, a, r]), B(() => {
    const f = k.current;
    f && (f.scrollTop = f.scrollHeight);
  }, [F]), B(() => {
    if (!i)
      return;
    const f = (b) => {
      if (b.key === "Escape") {
        o(!1), y.current?.focus();
        return;
      }
      if (b.key !== "Tab")
        return;
      const x = v.current?.querySelectorAll("button, textarea, a[href]");
      if (!x || x.length === 0)
        return;
      const $ = x[0], C = x[x.length - 1], N = e.shadowRoot?.activeElement;
      b.shiftKey && N === $ ? (b.preventDefault(), C?.focus()) : !b.shiftKey && N === C && (b.preventDefault(), $?.focus());
    };
    return e.addEventListener("keydown", f), () => e.removeEventListener("keydown", f);
  }, [i, e]), B(() => {
    i && v.current?.querySelector("textarea")?.focus();
  }, [i]);
  const P = Se(
    (f, b) => {
      p(
        (x) => x.map(($) => $.id === f ? { ...$, rating: b } : $)
      ), r.rate(f, b);
    },
    [r]
  ), V = Se(
    (f) => {
      c(null), l(!0);
      const b = `pending-${Date.now()}`;
      p((C) => [
        ...C,
        { id: `visitor-${Date.now()}`, role: "visitor", text: f, citations: [], rating: null },
        { id: b, role: "clerk", text: "", citations: [], streaming: !0, rating: null }
      ]);
      const x = (C) => {
        p(
          (N) => N.map((O) => O.id === b ? { ...O, ...C } : O)
        );
      };
      let $ = "";
      dt(r, f, H.current, {
        onStart: () => {
        },
        onDelta: (C) => {
          $ += C, x({ text: $ });
        },
        onReplace: (C) => {
          $ = C, x({ text: $ });
        },
        onCitations: (C) => x({ citations: C }),
        onDone: (C) => {
          x({ streaming: !1 }), l(!1), C?.awaiting_human === !0 && (u(!0), p((N) => N.filter((O) => O.id !== b)));
        },
        onError: (C) => {
          x({ streaming: !1, failed: !0 }), l(!1), c(C === "expired" ? n.expired : n.offline);
        }
      }).then((C) => {
        H.current = C, r.rememberTransport(C);
      }).catch(() => {
        x({ streaming: !1, failed: !0 }), l(!1), c(n.offline);
      });
    },
    [r, n]
  );
  if (!i) {
    const f = t.agent.widget_config.launcher;
    return /* @__PURE__ */ _(
      "button",
      {
        ref: y,
        type: "button",
        class: `launcher${f ? "" : " icon-only"}`,
        "aria-label": n.open,
        "aria-expanded": !1,
        onClick: () => o(!0),
        children: [
          /* @__PURE__ */ _(St, {}),
          f ? /* @__PURE__ */ _("span", { children: f }) : null
        ]
      }
    );
  }
  return /* @__PURE__ */ _("div", { class: "panel", role: "dialog", "aria-label": t.agent.name, ref: v, children: [
    /* @__PURE__ */ _("div", { class: "header", children: [
      t.agent.avatar_url ? /* @__PURE__ */ _("img", { class: "avatar", src: t.agent.avatar_url, alt: "", width: "34", height: "34" }) : /* @__PURE__ */ _("div", { class: "avatar", "aria-hidden": "true", children: t.agent.name.slice(0, 1).toUpperCase() }),
      /* @__PURE__ */ _("div", { class: "identity", children: [
        /* @__PURE__ */ _("div", { class: "name", children: t.agent.name }),
        /* @__PURE__ */ _("div", { class: "status", children: t.agent.widget_config.subtitle || n.subtitle })
      ] }),
      /* @__PURE__ */ _(
        "button",
        {
          type: "button",
          class: "icon-button",
          "aria-label": n.minimise,
          onClick: () => {
            o(!1), y.current?.focus();
          },
          children: /* @__PURE__ */ _($t, {})
        }
      ),
      /* @__PURE__ */ _(
        "button",
        {
          type: "button",
          class: "icon-button",
          "aria-label": n.close,
          onClick: () => {
            o(!1), y.current?.focus();
          },
          children: /* @__PURE__ */ _(Ct, {})
        }
      )
    ] }),
    /* @__PURE__ */ _("div", { class: "log", ref: k, role: "log", "aria-live": "polite", "aria-atomic": "false", children: [
      F.map((f) => /* @__PURE__ */ _(kt, { message: f, labels: n, onRate: P }, f.id)),
      a ? /* @__PURE__ */ _("div", { class: "notice", children: m ? n.humanHere : n.waitingHuman }) : null,
      d ? /* @__PURE__ */ _("div", { class: "error", children: d }) : null
    ] }),
    t.capabilities.handoff && !a ? /* @__PURE__ */ _(
      "button",
      {
        type: "button",
        class: "handoff",
        onClick: () => {
          u(!0), r.handoff().then((f) => {
            if (!f) {
              u(!1), c(n.offline);
              return;
            }
            r.transcript().then((b) => {
              b.messages.length > 0 && p(b.messages);
            });
          });
        },
        children: n.askHuman
      }
    ) : null,
    /* @__PURE__ */ _(Ft, { labels: n, busy: s, onSend: V }),
    t.agent.branding.show_badge ? /* @__PURE__ */ _("div", { class: "badge", children: t.agent.branding.label }) : null
  ] });
}
const At = `
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
`, Dt = `
${At}

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
`, Ae = "hvc-widget-root";
function De() {
  const t = window.HVC_WIDGET;
  if (!t?.agent || document.getElementById(Ae))
    return;
  const e = document.createElement("div");
  e.id = Ae, e.setAttribute("data-position", t.agent.widget_config.position), e.setAttribute("data-theme", Pt(t.agent.widget_config.theme));
  const n = e.attachShadow({ mode: "open" }), r = document.createElement("style");
  r.textContent = Dt, n.appendChild(r);
  const i = t.agent.widget_config.accent;
  e.style.setProperty("--hvc-brand", i), e.style.setProperty("--hvc-radius", `${t.agent.widget_config.radius}px`);
  const o = document.createElement("div");
  n.appendChild(o), document.body.appendChild(e), nt(Oe(Et, { boot: t, host: e }), o), t.agent.widget_config.theme === "auto" && window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").addEventListener(
    "change",
    (s) => e.setAttribute("data-theme", s.matches ? "dark" : "light")
  );
}
function Pt(t) {
  return t !== "auto" ? t : window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}
document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", De, { once: !0 }) : De();
