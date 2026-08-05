import{r as z,a as M}from"./react-cwCcgo7z.js";function j(r){var t,u,e="";if(typeof r=="string"||typeof r=="number")e+=r;else if(typeof r=="object")if(Array.isArray(r)){var l=r.length;for(t=0;t<l;t++)r[t]&&(u=j(r[t]))&&(e&&(e+=" "),e+=u)}else for(u in r)r[u]&&(e&&(e+=" "),e+=u);return e}function O(){for(var r,t,u=0,e="",l=arguments.length;u<l;u++)(r=arguments[u])&&(t=j(r))&&(e&&(e+=" "),e+=t);return e}var U=z(),R={exports:{}},S={};/**
 * @license React
 * use-sync-external-store-with-selector.production.js
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */var V;function _(){if(V)return S;V=1;var r=M();function t(i,a){return i===a&&(i!==0||1/i===1/a)||i!==i&&a!==a}var u=typeof Object.is=="function"?Object.is:t,e=r.useSyncExternalStore,l=r.useRef,D=r.useEffect,w=r.useMemo,x=r.useDebugValue;return S.useSyncExternalStoreWithSelector=function(i,a,y,b,s){var n=l(null);if(n.current===null){var f={hasValue:!1,value:null};n.current=f}else f=n.current;n=w(function(){function h(o){if(!p){if(p=!0,m=o,o=b(o),s!==void 0&&f.hasValue){var c=f.value;if(s(c,o))return d=c}return d=o}if(c=d,u(m,o))return c;var W=b(o);return s!==void 0&&s(c,W)?(m=o,c):(m=o,d=W)}var p=!1,m,d,E=y===void 0?null:y;return[function(){return h(a())},E===null?void 0:function(){return h(E())}]},[a,y,b,s]);var v=e(i,n[0],n[1]);return D(function(){f.hasValue=!0,f.value=v},[v]),x(v),v},S}var g;function q(){return g||(g=1,R.exports=_()),R.exports}var G=q();export{O as c,U as r,G as w};
