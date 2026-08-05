var Ve = Object.defineProperty;
var Ke = (t, e, n) => e in t ? Ve(t, e, { enumerable: !0, configurable: !0, writable: !0, value: n }) : t[e] = n;
var ce = (t, e, n) => Ke(t, typeof e != "symbol" ? e + "" : e, n);
var G, m, He, A, _e, De, Ae, Z, z, I, Me, oe, te, ne, J = {}, X = [], qe = /acit|ex(?:s|g|n|p|$)|rph|grid|ows|mnc|ntw|ine[ch]|zoo|^ord|itera/i, Q = Array.isArray;
function H(t, e) {
  for (var n in e) t[n] = e[n];
  return t;
}
function ie(t) {
  t && t.parentNode && t.parentNode.removeChild(t);
}
function Pe(t, e, n) {
  var r, i, o, s = {};
  for (o in e) o == "key" ? r = e[o] : o == "ref" ? i = e[o] : s[o] = e[o];
  if (arguments.length > 2 && (s.children = arguments.length > 3 ? G.call(arguments, 2) : n), typeof t == "function" && t.defaultProps != null) for (o in t.defaultProps) s[o] === void 0 && (s[o] = t.defaultProps[o]);
  return V(t, s, r, i, null);
}
function V(t, e, n, r, i) {
  var o = { type: t, props: e, key: n, ref: r, __k: null, __: null, __b: 0, __e: null, __c: null, constructor: void 0, __v: i ?? ++He, __i: -1, __u: 0 };
  return i == null && m.vnode != null && m.vnode(o), o;
}
function L(t) {
  return t.children;
}
function K(t, e) {
  this.props = t, this.context = e;
}
function M(t, e) {
  if (e == null) return t.__ ? M(t.__, t.__i + 1) : null;
  for (var n; e < t.__k.length; e++) if ((n = t.__k[e]) != null && n.__e != null) return n.__e;
  return typeof t.type == "function" ? M(t) : null;
}
function Je(t) {
  if (t.__P && t.__d) {
    var e = t.__v, n = e.__e, r = [], i = [], o = H({}, e);
    o.__v = e.__v + 1, m.vnode && m.vnode(o), ae(t.__P, o, e, t.__n, t.__P.namespaceURI, 32 & e.__u ? [n] : null, r, n ?? M(e), !!(32 & e.__u), i), o.__v = e.__v, o.__.__k[o.__i] = o, Ue(r, o, i), e.__e = e.__ = null, o.__e != n && Ne(o);
  }
}
function Ne(t) {
  if ((t = t.__) != null && t.__c != null) return t.__e = t.__c.base = null, t.__k.some(function(e) {
    if (e != null && e.__e != null) return t.__e = t.__c.base = e.__e;
  }), Ne(t);
}
function ue(t) {
  (!t.__d && (t.__d = !0) && A.push(t) && !Y.__r++ || _e != m.debounceRendering) && ((_e = m.debounceRendering) || De)(Y);
}
function Y() {
  try {
    for (var t, e = 1; A.length; ) A.length > e && A.sort(Ae), t = A.shift(), e = A.length, Je(t);
  } finally {
    A.length = Y.__r = 0;
  }
}
function Ie(t, e, n, r, i, o, s, c, d, l, p) {
  var h, a, u, g, $, y, x = r && r.__k || X, f = e.length;
  for (d = Xe(n, e, x, d, f), h = 0; h < f; h++) (u = n.__k[h]) != null && (a = u.__i != -1 && x[u.__i] || J, u.__i = h, y = ae(t, u, a, i, o, s, c, d, l, p), g = u.__e, u.ref && a.ref != u.ref && (a.ref && se(a.ref, null, u), p.push(u.ref, u.__c || g, u)), $ == null && g != null && ($ = g), 4 & u.__u ? (d = Be(u, d, t), a.__e && (a.__e = null)) : typeof u.type == "function" && y !== void 0 ? d = y : g && (d = g.nextSibling), u.__u &= -7);
  return n.__e = $, d;
}
function Xe(t, e, n, r, i) {
  var o, s, c, d, l, p = n.length, h = p, a = 0;
  for (t.__k = new Array(i), o = 0; o < i; o++) (s = e[o]) != null && typeof s != "boolean" && typeof s != "function" ? (typeof s == "string" || typeof s == "number" || typeof s == "bigint" || s.constructor == String ? s = t.__k[o] = V(null, s, null, null, null) : Q(s) ? s = t.__k[o] = V(L, { children: s }, null, null, null) : s.constructor === void 0 && s.__b > 0 ? s = t.__k[o] = V(s.type, s.props, s.key, s.ref ? s.ref : null, s.__v) : t.__k[o] = s, d = o + a, s.__ = t, s.__b = t.__b + 1, c = null, (l = s.__i = Ye(s, n, d, h)) != -1 && (h--, (c = n[l]) && (c.__u |= 2)), c == null || c.__v == null ? (l == -1 && (i > p ? a-- : i < p && a++), typeof s.type != "function" && (s.__u |= 4)) : l != d && (l == d - 1 ? a-- : l == d + 1 ? a++ : (l > d ? a-- : a++, s.__u |= 4))) : t.__k[o] = null;
  if (h) for (o = 0; o < p; o++) (c = n[o]) != null && (2 & c.__u) == 0 && (c.__e == r && (r = M(c)), je(c, c));
  return r;
}
function Be(t, e, n) {
  var r, i;
  if (typeof t.type == "function") {
    for (r = t.__k, i = 0; r && i < r.length; i++) r[i] && (r[i].__ = t, e = Be(r[i], e, n));
    return e;
  }
  t.__e != e && (e && t.type && !e.parentNode && (e = M(t)), e = n.insertBefore(t.__e, e || null));
  do
    e = e && e.nextSibling;
  while (e != null && e.nodeType == 8);
  return e;
}
function Ye(t, e, n, r) {
  var i, o, s, c = t.key, d = t.type, l = e[n], p = l != null && (2 & l.__u) == 0;
  if (l === null && c == null || p && c == l.key && d == l.type) return n;
  if (r > (p ? 1 : 0)) {
    for (i = n - 1, o = n + 1; i >= 0 || o < e.length; ) if ((l = e[s = i >= 0 ? i-- : o++]) != null && (2 & l.__u) == 0 && c == l.key && d == l.type) return s;
  }
  return -1;
}
function de(t, e, n) {
  e[0] == "-" ? t.setProperty(e, n ?? "") : t[e] = n == null ? "" : typeof n != "number" || qe.test(e) ? n : n + "px";
}
function j(t, e, n, r, i) {
  var o, s;
  e: if (e == "style") if (typeof n == "string") t.style.cssText = n;
  else {
    if (typeof r == "string" && (t.style.cssText = r = ""), r) for (e in r) n && e in n || de(t.style, e, "");
    if (n) for (e in n) r && n[e] == r[e] || de(t.style, e, n[e]);
  }
  else if (e[0] == "o" && e[1] == "n") o = e != (e = e.replace(Me, "$1")), s = e.toLowerCase(), e = s in t || e == "onFocusOut" || e == "onFocusIn" ? s.slice(2) : e.slice(2), t.l || (t.l = {}), t.l[e + o] = n, n ? r ? n[I] = r[I] : (n[I] = oe, t.addEventListener(e, o ? ne : te, o)) : t.removeEventListener(e, o ? ne : te, o);
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
function pe(t) {
  return function(e) {
    if (this.l) {
      var n = this.l[e.type + t];
      if (e[z] == null) e[z] = oe++;
      else if (e[z] < n[I]) return;
      return n(m.event ? m.event(e) : e);
    }
  };
}
function ae(t, e, n, r, i, o, s, c, d, l) {
  var p, h, a, u, g, $, y, x, f, E, v, k, S, T, b, D, F = e.type;
  if (e.constructor !== void 0) return null;
  128 & n.__u && (d = !!(32 & n.__u), o = [c = e.__e = n.__e]), (p = m.__b) && p(e);
  e: if (typeof F == "function") {
    h = s.length;
    try {
      if (f = e.props, E = F.prototype && F.prototype.render, v = (p = F.contextType) && r[p.__c], k = p ? v ? v.props.value : p.__ : r, n.__c ? x = (a = e.__c = n.__c).__ = a.__E : (E ? e.__c = a = new F(f, k) : (e.__c = a = new K(f, k), a.constructor = F, a.render = Qe), v && v.sub(a), a.state || (a.state = {}), a.__n = r, u = a.__d = !0, a.__h = [], a._sb = []), E && a.__s == null && (a.__s = a.state), E && F.getDerivedStateFromProps != null && (a.__s == a.state && (a.__s = H({}, a.__s)), H(a.__s, F.getDerivedStateFromProps(f, a.__s))), g = a.props, $ = a.state, a.__v = e, u) E && F.getDerivedStateFromProps == null && a.componentWillMount != null && a.componentWillMount(), E && a.componentDidMount != null && a.__h.push(a.componentDidMount);
      else {
        if (E && F.getDerivedStateFromProps == null && f !== g && a.componentWillReceiveProps != null && a.componentWillReceiveProps(f, k), e.__v == n.__v || !a.__e && a.shouldComponentUpdate != null && a.shouldComponentUpdate(f, a.__s, k) === !1) {
          e.__v != n.__v && (a.props = f, a.state = a.__s, a.__d = !1), e.__e = n.__e, e.__k = n.__k, e.__k.some(function(P) {
            P && (P.__ = e);
          }), X.push.apply(a.__h, a._sb), a._sb = [], a.__h.length && s.push(a), c = M(n);
          break e;
        }
        a.componentWillUpdate != null && a.componentWillUpdate(f, a.__s, k), E && a.componentDidUpdate != null && a.__h.push(function() {
          a.componentDidUpdate(g, $, y);
        });
      }
      if (a.context = k, a.props = f, a.__P = t, a.__e = !1, S = m.__r, T = 0, E) a.state = a.__s, a.__d = !1, S && S(e), p = a.render(a.props, a.state, a.context), X.push.apply(a.__h, a._sb), a._sb = [];
      else do
        a.__d = !1, S && S(e), p = a.render(a.props, a.state, a.context), a.state = a.__s;
      while (a.__d && ++T < 25);
      a.state = a.__s, a.getChildContext != null && (r = H(H({}, r), a.getChildContext())), E && !u && a.getSnapshotBeforeUpdate != null && (y = a.getSnapshotBeforeUpdate(g, $)), b = p != null && p.type === L && p.key == null ? Le(p.props.children) : p, c = Ie(t, Q(b) ? b : [b], e, n, r, i, o, s, c, d, l), a.base = e.__e, e.__u &= -161, a.__h.length && s.push(a), x && (a.__E = a.__ = null);
    } catch (P) {
      if (s.length = h, e.__v = null, d || o != null) {
        if (P.then) {
          for (e.__u |= d ? 160 : 128; c && c.nodeType == 8 && c.nextSibling; ) c = c.nextSibling;
          o != null && (o[o.indexOf(c)] = null), e.__e = c;
        } else if (o != null) for (D = o.length; D--; ) ie(o[D]);
      } else e.__e = n.__e;
      e.__k == null && (e.__k = n.__k || []), P.then || Oe(e), m.__e(P, e, n);
    }
  } else o == null && e.__v == n.__v ? (e.__k = n.__k, e.__e = n.__e) : c = e.__e = Ge(n.__e, e, n, r, i, o, s, d, l);
  return (p = m.diffed) && p(e), 128 & e.__u ? void 0 : c;
}
function Oe(t) {
  t && (t.__c && (t.__c.__e = !0), t.__k && t.__k.some(Oe));
}
function Ue(t, e, n) {
  for (var r = 0; r < n.length; r++) se(n[r], n[++r], n[++r]);
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
function Le(t) {
  return typeof t != "object" || t == null || t.__b > 0 ? t : Q(t) ? t.map(Le) : t.constructor !== void 0 ? null : H({}, t);
}
function Ge(t, e, n, r, i, o, s, c, d) {
  var l, p, h, a, u, g, $, y = n.props || J, x = e.props, f = e.type;
  if (f == "svg" ? i = "http://www.w3.org/2000/svg" : f == "math" ? i = "http://www.w3.org/1998/Math/MathML" : i || (i = "http://www.w3.org/1999/xhtml"), o != null) {
    for (l = 0; l < o.length; l++) if ((u = o[l]) && "setAttribute" in u == !!f && (f ? u.localName == f : u.nodeType == 3)) {
      t = u, o[l] = null;
      break;
    }
  }
  if (t == null) {
    if (f == null) return document.createTextNode(x);
    t = document.createElementNS(i, f, x.is && x), c && (m.__m && m.__m(e, o), c = !1), o = null;
  }
  if (f == null) y === x || c && t.data == x || (t.data = x);
  else {
    if (o = f == "textarea" && x.defaultValue != null ? null : o && G.call(t.childNodes), !c && o != null) for (y = {}, l = 0; l < t.attributes.length; l++) y[(u = t.attributes[l]).name] = u.value;
    for (l in y) u = y[l], l == "dangerouslySetInnerHTML" ? h = u : l == "children" || l in x || l == "value" && "defaultValue" in x || l == "checked" && "defaultChecked" in x || j(t, l, null, u, i);
    for (l in x) u = x[l], l == "children" ? a = u : l == "dangerouslySetInnerHTML" ? p = u : l == "value" ? g = u : l == "checked" ? $ = u : c && typeof u != "function" || y[l] === u || j(t, l, u, y[l], i);
    if (p) c || h && (p.__html == h.__html || p.__html == t.innerHTML) || (t.innerHTML = p.__html), e.__k = [];
    else if (h && (t.innerHTML = ""), Ie(e.type == "template" ? t.content : t, Q(a) ? a : [a], e, n, r, f == "foreignObject" ? "http://www.w3.org/1999/xhtml" : i, o, s, o ? o[0] : n.__k && M(n, 0), c, d), o != null) for (l = o.length; l--; ) ie(o[l]);
    c && f != "textarea" || (l = "value", f == "progress" && g == null ? t.removeAttribute("value") : g != null && (g !== t[l] || f == "progress" && !g || f == "option" && g != y[l]) && j(t, l, g, y[l], i), l = "checked", $ != null && $ != t[l] && j(t, l, $, y[l], i));
  }
  return t;
}
function se(t, e, n) {
  try {
    if (typeof t == "function") {
      var r = typeof t.__u == "function";
      r && t.__u(), r && e == null || (t.__u = t(e));
    } else t.current = e;
  } catch (i) {
    m.__e(i, n);
  }
}
function je(t, e, n) {
  var r, i;
  if (m.unmount && m.unmount(t), (r = t.ref) && (r.current && r.current != t.__e || se(r, null, e)), (r = t.__c) != null) {
    if (r.componentWillUnmount) try {
      r.componentWillUnmount();
    } catch (o) {
      m.__e(o, e);
    }
    r.base = r.__P = r.__n = null;
  }
  if (r = t.__k) for (i = 0; i < r.length; i++) r[i] && je(r[i], e, n || typeof t.type != "function");
  n || ie(t.__e), t.__c = t.__ = t.__e = void 0;
}
function Qe(t, e, n) {
  return this.constructor(t, n);
}
function Ze(t, e, n) {
  var r, i, o, s;
  e == document && (e = document.documentElement), m.__ && m.__(t, e), i = (r = !1) ? null : e.__k, o = [], s = [], ae(e, t = e.__k = Pe(L, null, [t]), i || J, J, e.namespaceURI, i ? null : e.firstChild ? G.call(e.childNodes) : null, o, i ? i.__e : e.firstChild, r, s), Ue(o, t, s), t.props.children = null;
}
G = X.slice, m = { __e: function(t, e, n, r) {
  for (var i, o, s; e = e.__; ) if ((i = e.__c) && !i.__) try {
    if ((o = i.constructor) && o.getDerivedStateFromError != null && (i.setState(o.getDerivedStateFromError(t)), s = i.__d), i.componentDidCatch != null && (i.componentDidCatch(t, r || {}), s = i.__d), s) return i.__E = i;
  } catch (c) {
    t = c;
  }
  throw t;
} }, He = 0, K.prototype.setState = function(t, e) {
  var n;
  n = this.__s != null && this.__s != this.state ? this.__s : this.__s = H({}, this.state), typeof t == "function" && (t = t(H({}, n), this.props)), t && H(n, t), t != null && this.__v && (e && this._sb.push(e), ue(this));
}, K.prototype.forceUpdate = function(t) {
  this.__v && (this.__e = !0, t && this.__h.push(t), ue(this));
}, K.prototype.render = L, A = [], De = typeof Promise == "function" ? Promise.prototype.then.bind(Promise.resolve()) : setTimeout, Ae = function(t, e) {
  return t.__v.__b - e.__v.__b;
}, Y.__r = 0, Z = Math.random().toString(8), z = "__d" + Z, I = "__a" + Z, Me = /(PointerCapture)$|Capture$/i, oe = 0, te = pe(!1), ne = pe(!0);
var et = 0;
function _(t, e, n, r, i, o) {
  e || (e = {});
  var s, c, d = e;
  if ("ref" in d) for (c in d = {}, e) c == "ref" ? s = e[c] : d[c] = e[c];
  var l = { type: t, props: d, key: n, ref: s, __k: null, __: null, __b: 0, __e: null, __c: null, constructor: void 0, __v: --et, __i: -1, __u: 0, __source: i, __self: o };
  if (typeof t == "function" && (s = t.defaultProps)) for (c in s) d[c] === void 0 && (d[c] = s[c]);
  return m.vnode && m.vnode(l), l;
}
var O, w, ee, he, U = 0, Re = [], C = m, fe = C.__b, ve = C.__r, ge = C.diffed, me = C.__c, be = C.unmount, ye = C.__;
function le(t, e) {
  C.__h && C.__h(w, t, U || e), U = 0;
  var n = w.__H || (w.__H = { __: [], __h: [] });
  return t >= n.__.length && n.__.push({}), n.__[t];
}
function R(t) {
  return U = 1, tt(ze, t);
}
function tt(t, e, n) {
  var r = le(O++, 2);
  if (r.t = t, !r.__c && (r.__ = [ze(void 0, e), function(c) {
    var d = r.__N ? r.__N[0] : r.__[0], l = r.t(d, c);
    d !== l && (r.__N = [l, r.__[1]], r.__c.setState({}));
  }], r.__c = w, !w.__f)) {
    var i = function(c, d, l) {
      if (!r.__c.__H) return !0;
      var p = !1, h = r.__c.props !== c;
      if (r.__c.__H.__.some(function(u) {
        if (u.__N) {
          p = !0;
          var g = u.__[0];
          u.__ = u.__N, u.__N = void 0, g !== u.__[0] && (h = !0);
        }
      }), o) {
        var a = o.call(this, c, d, l);
        return p ? a || h : a;
      }
      return !p || h;
    };
    w.__f = !0;
    var o = w.shouldComponentUpdate, s = w.componentWillUpdate;
    w.componentWillUpdate = function(c, d, l) {
      if (this.__e) {
        var p = o;
        o = void 0, i(c, d, l), o = p;
      }
      s && s.call(this, c, d, l);
    }, w.shouldComponentUpdate = i;
  }
  return r.__N || r.__;
}
function W(t, e) {
  var n = le(O++, 3);
  !C.__s && We(n.__H, e) && (n.__ = t, n.u = e, w.__H.__h.push(n));
}
function N(t) {
  return U = 5, B(function() {
    return { current: t };
  }, []);
}
function B(t, e) {
  var n = le(O++, 7);
  return We(n.__H, e) && (n.__ = t(), n.__H = e, n.__h = t), n.__;
}
function xe(t, e) {
  return U = 8, B(function() {
    return t;
  }, e);
}
function nt() {
  for (var t; t = Re.shift(); ) {
    var e = t.__H;
    if (t.__P && e) try {
      e.__h.some(q), e.__h.some(re), e.__h = [];
    } catch (n) {
      e.__h = [], C.__e(n, t.__v);
    }
  }
}
C.__b = function(t) {
  w = null, fe && fe(t);
}, C.__ = function(t, e) {
  t && e.__k && e.__k.__m && (t.__m = e.__k.__m), ye && ye(t, e);
}, C.__r = function(t) {
  ve && ve(t), O = 0;
  var e = (w = t.__c).__H;
  e && (ee === w ? (e.__h = [], w.__h = [], e.__.some(function(n) {
    n.__N && (n.__ = n.__N), n.u = n.__N = void 0;
  })) : (e.__h.some(q), e.__h.some(re), e.__h = [], O = 0)), ee = w;
}, C.diffed = function(t) {
  ge && ge(t);
  var e = t.__c;
  e && e.__H && (e.__H.__h.length && (Re.push(e) !== 1 && he === C.requestAnimationFrame || ((he = C.requestAnimationFrame) || rt)(nt)), e.__H.__.some(function(n) {
    n.u && (n.__H = n.u, n.u = void 0);
  })), ee = w = null;
}, C.__c = function(t, e) {
  e.some(function(n) {
    try {
      n.__h.some(q), n.__h = n.__h.filter(function(r) {
        return !r.__ || re(r);
      });
    } catch (r) {
      e.some(function(i) {
        i.__h && (i.__h = []);
      }), e = [], C.__e(r, n.__v);
    }
  }), me && me(t, e);
}, C.unmount = function(t) {
  be && be(t);
  var e, n = t.__c;
  n && n.__H && (n.__H.__.some(function(r) {
    try {
      q(r);
    } catch (i) {
      e = i;
    }
  }), n.__H = void 0, e && C.__e(e, n.__v));
};
var we = typeof requestAnimationFrame == "function";
function rt(t) {
  var e, n = function() {
    clearTimeout(r), we && cancelAnimationFrame(e), setTimeout(t);
  }, r = setTimeout(n, 35);
  we && (e = requestAnimationFrame(n));
}
function q(t) {
  var e = w, n = t.__c;
  typeof n == "function" && (t.__c = void 0, n()), w = e;
}
function re(t) {
  var e = w;
  t.__c = t.__(), w = e;
}
function We(t, e) {
  return !t || t.length !== e.length || e.some(function(n, r) {
    return n !== t[r];
  });
}
function ze(t, e) {
  return typeof e == "function" ? e(t) : e;
}
const ot = "hvc.session", ke = "hvc.transport";
function Se(t) {
  try {
    return window.sessionStorage.getItem(t);
  } catch {
    return null;
  }
}
function Ce(t, e) {
  try {
    window.sessionStorage.setItem(t, e);
  } catch {
  }
}
class it {
  constructor(e) {
    ce(this, "session", null);
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
    return this.session = r, Ce(this.key(), JSON.stringify(r)), r.token;
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
    const e = Se(ke);
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
    Ce(ke, e);
  }
  /** Restore the transcript after a page navigation. */
  async history() {
    if (!this.session)
      return [];
    const e = await fetch(`${this.boot.rest_url}/public/chat/history`, {
      headers: { "X-HVC-Session": this.session.token }
    });
    return e.ok ? (await e.json()).data.messages.map((r) => ({
      id: r.id,
      role: r.role,
      text: r.text,
      citations: r.citations ?? [],
      rating: r.rating === 1 ? 1 : r.rating === -1 ? -1 : null
    })) : (e.status === 401 && this.forget(), []);
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
    return `${ot}.${this.boot.agent.uuid}`;
  }
  restore() {
    const e = Se(this.key());
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
const at = 2500, st = 260, lt = 9e4;
async function ct(t, e, n, r) {
  return n === "poll" ? (await $e(t, e, r), "poll") : await _t(t, e, r) ? "sse" : (await $e(t, e, r), "poll");
}
async function _t(t, e, n) {
  const r = await t.token(), i = new AbortController();
  let o = !1;
  const s = window.setTimeout(() => {
    o || i.abort();
  }, at);
  try {
    const c = await fetch(t.url("/public/chat/stream"), {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "text/event-stream",
        "X-HVC-Session": r
      },
      body: JSON.stringify({ message: e, url: window.location.href, title: document.title }),
      signal: i.signal
    });
    if (c.status === 401)
      return t.forget(), window.clearTimeout(s), n.onError("expired"), !0;
    if (!c.ok || !c.body)
      return window.clearTimeout(s), !1;
    const d = c.body.getReader(), l = new TextDecoder();
    let p = "";
    for (; ; ) {
      const { done: h, value: a } = await d.read();
      if (h)
        break;
      o || (o = !0, window.clearTimeout(s)), p += l.decode(a, { stream: !0 });
      const u = p.split(`

`);
      p = u.pop() ?? "";
      for (const g of u)
        ut(g, n);
    }
    return window.clearTimeout(s), o;
  } catch {
    return window.clearTimeout(s), !1;
  }
}
function ut(t, e) {
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
      e.onDone();
      break;
    case "error":
      e.onError(String(i.message ?? ""));
      break;
  }
}
async function $e(t, e, n) {
  const r = await t.token(), i = pt();
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
  const c = Date.now() + lt;
  let d = 0;
  for (; ; ) {
    if (Date.now() > c) {
      n.onError("timeout");
      return;
    }
    await dt(st);
    const l = await fetch(
      t.url(`/public/chat/poll?message=${i}&cursor=${d}`),
      { headers: { "X-HVC-Session": r } }
    );
    if (l.status === 401) {
      t.forget(), n.onError("expired");
      return;
    }
    if (!l.ok)
      continue;
    const h = (await l.json()).data;
    if (!h.pending && (o || (o = !0, n.onStart(h.message_id ?? "")), h.replaced ? n.onReplace(h.text) : h.text && n.onDelta(h.text), d = h.cursor, h.complete)) {
      if (h.error) {
        n.onError(h.error.message);
        return;
      }
      h.citations?.length && n.onCitations(h.citations), n.onDone();
      return;
    }
  }
}
function dt(t) {
  return new Promise((e) => window.setTimeout(e, t));
}
function pt() {
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
const ht = {
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
  subtitle: "Usually replies instantly"
};
function ft(t) {
  return ht;
}
const vt = /(`[^`]+`)|(\*\*[^*]+\*\*)|(__[^_]+__)|(\*[^*\n]+\*)|(\[[^\]]+\]\([^)\s]+\))/;
function gt(t) {
  const e = [], n = t.split(`
`);
  let r = [], i = null, o = 0;
  const s = () => {
    r.length && (e.push(/* @__PURE__ */ _("p", { children: Te(r.join(" ")) }, `p${o++}`)), r = []);
  }, c = () => {
    if (!i)
      return;
    const d = i.items.map((l, p) => /* @__PURE__ */ _("li", { children: Te(l) }, `li${p}`));
    e.push(
      i.ordered ? /* @__PURE__ */ _("ol", { children: d }, `l${o++}`) : /* @__PURE__ */ _("ul", { children: d }, `l${o++}`)
    ), i = null;
  };
  for (const d of n) {
    const l = d.trimEnd();
    if (!l.trim()) {
      s(), c();
      continue;
    }
    const p = /^\s*[-*+]\s+(.*)$/.exec(l), h = /^\s*\d+[.)]\s+(.*)$/.exec(l);
    if (p || h) {
      s();
      const a = !!h, u = p?.[1] ?? h?.[1];
      (!i || i.ordered !== a) && (c(), i = { ordered: a, items: [] }), i.items.push(u);
      continue;
    }
    c(), r.push(l.trim());
  }
  return s(), c(), e;
}
function Te(t) {
  const e = [];
  let n = t, r = 0;
  for (; ; ) {
    const i = vt.exec(n);
    if (!i || i.index === void 0)
      break;
    i.index > 0 && e.push(n.slice(0, i.index));
    const o = i[0];
    e.push(mt(o, r++)), n = n.slice(i.index + o.length);
  }
  return n && e.push(n), e;
}
function mt(t, e) {
  if (t.startsWith("`"))
    return /* @__PURE__ */ _("code", { children: t.slice(1, -1) }, e);
  if (t.startsWith("**") || t.startsWith("__"))
    return /* @__PURE__ */ _("strong", { children: t.slice(2, -2) }, e);
  if (t.startsWith("*"))
    return /* @__PURE__ */ _("em", { children: t.slice(1, -1) }, e);
  const n = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(t);
  if (n) {
    const r = bt(n[2] ?? "");
    return r ? /* @__PURE__ */ _("a", { href: r, target: "_blank", rel: "noopener noreferrer nofollow", children: n[1] }, e) : n[1] ?? t;
  }
  return t;
}
function bt(t) {
  const e = t.trim();
  return e.startsWith("/") || e.startsWith("#") || /^https?:\/\//i.test(e) ? e : null;
}
function yt({ message: t, labels: e, onRate: n }) {
  const r = t.role === "clerk", i = !!t.streaming && t.text === "";
  return /* @__PURE__ */ _("div", { class: `row ${t.role}`, children: /* @__PURE__ */ _("div", { class: "bubble", children: [
    i ? /* @__PURE__ */ _("div", { class: "typing", "aria-label": e.thinking, children: [
      /* @__PURE__ */ _("span", {}),
      /* @__PURE__ */ _("span", {}),
      /* @__PURE__ */ _("span", {})
    ] }) : gt(t.text),
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
    r && !t.streaming && t.text !== "" && /* @__PURE__ */ _("div", { class: "feedback", children: t.rating ? /* @__PURE__ */ _("span", { class: "note", children: e.rated }) : /* @__PURE__ */ _(L, { children: [
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
function xt() {
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
function wt() {
  return /* @__PURE__ */ _("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ _("path", { d: "m6 6 12 12M18 6 6 18", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round" }) });
}
function kt() {
  return /* @__PURE__ */ _("svg", { width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", children: /* @__PURE__ */ _("path", { d: "M6 12h12", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round" }) });
}
function St() {
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
const Ct = 2e3;
function $t({ labels: t, busy: e, onSend: n }) {
  const r = N(null), i = () => {
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
        maxLength: Ct,
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
        children: /* @__PURE__ */ _(St, {})
      }
    )
  ] }) });
}
function Tt({ boot: t, host: e }) {
  const n = B(() => ft(t.agent.locale), [t.agent.locale]), r = B(() => new it(t), [t]), [i, o] = R(!1), [s, c] = R(!1), [d, l] = R(null), [p, h] = R([]), a = N(null), u = N(null), g = N(null), $ = N(r.transport() ?? "sse"), y = t.agent.greeting?.trim(), x = B(() => !y || p.length > 0 ? p : [{ id: "greeting", role: "clerk", text: y, citations: [], rating: null }], [y, p]);
  W(() => {
    !i || p.length > 0 || !r.conversation() || r.history().then((v) => {
      v.length > 0 && h(v);
    });
  }, [i, p.length, r]), W(() => {
    const v = a.current;
    v && (v.scrollTop = v.scrollHeight);
  }, [x]), W(() => {
    if (!i)
      return;
    const v = (k) => {
      if (k.key === "Escape") {
        o(!1), u.current?.focus();
        return;
      }
      if (k.key !== "Tab")
        return;
      const S = g.current?.querySelectorAll("button, textarea, a[href]");
      if (!S || S.length === 0)
        return;
      const T = S[0], b = S[S.length - 1], D = e.shadowRoot?.activeElement;
      k.shiftKey && D === T ? (k.preventDefault(), b?.focus()) : !k.shiftKey && D === b && (k.preventDefault(), T?.focus());
    };
    return e.addEventListener("keydown", v), () => e.removeEventListener("keydown", v);
  }, [i, e]), W(() => {
    i && g.current?.querySelector("textarea")?.focus();
  }, [i]);
  const f = xe(
    (v, k) => {
      h(
        (S) => S.map((T) => T.id === v ? { ...T, rating: k } : T)
      ), r.rate(v, k);
    },
    [r]
  ), E = xe(
    (v) => {
      l(null), c(!0);
      const k = `pending-${Date.now()}`;
      h((b) => [
        ...b,
        { id: `visitor-${Date.now()}`, role: "visitor", text: v, citations: [], rating: null },
        { id: k, role: "clerk", text: "", citations: [], streaming: !0, rating: null }
      ]);
      const S = (b) => {
        h(
          (D) => D.map((F) => F.id === k ? { ...F, ...b } : F)
        );
      };
      let T = "";
      ct(r, v, $.current, {
        onStart: () => {
        },
        onDelta: (b) => {
          T += b, S({ text: T });
        },
        onReplace: (b) => {
          T = b, S({ text: T });
        },
        onCitations: (b) => S({ citations: b }),
        onDone: () => {
          S({ streaming: !1 }), c(!1);
        },
        onError: (b) => {
          S({ streaming: !1, failed: !0 }), c(!1), l(b === "expired" ? n.expired : n.offline);
        }
      }).then((b) => {
        $.current = b, r.rememberTransport(b);
      }).catch(() => {
        S({ streaming: !1, failed: !0 }), c(!1), l(n.offline);
      });
    },
    [r, n]
  );
  if (!i) {
    const v = t.agent.widget_config.launcher;
    return /* @__PURE__ */ _(
      "button",
      {
        ref: u,
        type: "button",
        class: `launcher${v ? "" : " icon-only"}`,
        "aria-label": n.open,
        "aria-expanded": !1,
        onClick: () => o(!0),
        children: [
          /* @__PURE__ */ _(xt, {}),
          v ? /* @__PURE__ */ _("span", { children: v }) : null
        ]
      }
    );
  }
  return /* @__PURE__ */ _("div", { class: "panel", role: "dialog", "aria-label": t.agent.name, ref: g, children: [
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
            o(!1), u.current?.focus();
          },
          children: /* @__PURE__ */ _(kt, {})
        }
      ),
      /* @__PURE__ */ _(
        "button",
        {
          type: "button",
          class: "icon-button",
          "aria-label": n.close,
          onClick: () => {
            o(!1), u.current?.focus();
          },
          children: /* @__PURE__ */ _(wt, {})
        }
      )
    ] }),
    /* @__PURE__ */ _("div", { class: "log", ref: a, role: "log", "aria-live": "polite", "aria-atomic": "false", children: [
      x.map((v) => /* @__PURE__ */ _(yt, { message: v, labels: n, onRate: f }, v.id)),
      d ? /* @__PURE__ */ _("div", { class: "error", children: d }) : null
    ] }),
    /* @__PURE__ */ _($t, { labels: n, busy: s, onSend: E }),
    t.agent.branding.show_badge ? /* @__PURE__ */ _("div", { class: "badge", children: t.agent.branding.label }) : null
  ] });
}
const Ft = `
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
`, Et = `
${Ft}

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
`, Fe = "hvc-widget-root";
function Ee() {
  const t = window.HVC_WIDGET;
  if (!t?.agent || document.getElementById(Fe))
    return;
  const e = document.createElement("div");
  e.id = Fe, e.setAttribute("data-position", t.agent.widget_config.position), e.setAttribute("data-theme", Ht(t.agent.widget_config.theme));
  const n = e.attachShadow({ mode: "open" }), r = document.createElement("style");
  r.textContent = Et, n.appendChild(r);
  const i = t.agent.widget_config.accent;
  e.style.setProperty("--hvc-brand", i), e.style.setProperty("--hvc-radius", `${t.agent.widget_config.radius}px`);
  const o = document.createElement("div");
  n.appendChild(o), document.body.appendChild(e), Ze(Pe(Tt, { boot: t, host: e }), o), t.agent.widget_config.theme === "auto" && window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").addEventListener(
    "change",
    (s) => e.setAttribute("data-theme", s.matches ? "dark" : "light")
  );
}
function Ht(t) {
  return t !== "auto" ? t : window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}
document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", Ee, { once: !0 }) : Ee();
