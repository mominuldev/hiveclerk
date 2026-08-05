import{r as _}from"./react-DsLtSkcI.js";function j(r){var t,u,e="";if(typeof r=="string"||typeof r=="number")e+=r;else if(typeof r=="object")if(Array.isArray(r)){var l=r.length;for(t=0;t<l;t++)r[t]&&(u=j(r[t]))&&(e&&(e+=" "),e+=u)}else for(u in r)r[u]&&(e&&(e+=" "),e+=u);return e}function O(){for(var r,t,u=0,e="",l=arguments.length;u<l;u++)(r=arguments[u])&&(t=j(r))&&(e&&(e+=" "),e+=t);return e}var S={exports:{}},h={};/**
 * @license React
 * use-sync-external-store-with-selector.production.js
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */var V;function x(){if(V)return h;V=1;var r=_();function t(i,n){return i===n&&(i!==0||1/i===1/n)||i!==i&&n!==n}var u=typeof Object.is=="function"?Object.is:t,e=r.useSyncExternalStore,l=r.useRef,w=r.useEffect,z=r.useMemo,M=r.useDebugValue;return h.useSyncExternalStoreWithSelector=function(i,n,y,b,s){var f=l(null);if(f.current===null){var a={hasValue:!1,value:null};f.current=a}else a=f.current;f=z(function(){function R(o){if(!W){if(W=!0,m=o,o=b(o),s!==void 0&&a.hasValue){var c=a.value;if(s(c,o))return d=c}return d=o}if(c=d,u(m,o))return c;var E=b(o);return s!==void 0&&s(c,E)?(m=o,c):(m=o,d=E)}var W=!1,m,d,p=y===void 0?null:y;return[function(){return R(n())},p===null?void 0:function(){return R(p())}]},[n,y,b,s]);var v=e(i,f[0],f[1]);return w(function(){a.hasValue=!0,a.value=v},[v]),M(v),v},h}var g;function A(){return g||(g=1,S.exports=x()),S.exports}var U=A();export{O as c,U as w};
