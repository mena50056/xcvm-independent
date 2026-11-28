<?php
declare(strict_types=1);
namespace XcVm\Core\Proxy;

/** Server buttons configure upstreams; only agent acknowledgements enable routing. */
final class ServerProxyControl {
    public function __construct(private ProxyProfiles $profiles,private $tls=null) {}
    private function parents(array $ids): array {
        if(!$ids||count($ids)>100)throw new \InvalidArgumentException('INVALID_SERVERS');
        foreach($ids as $id)if(!is_int($id)||$id<1)throw new \InvalidArgumentException('INVALID_SERVERS');
        $all=array_column($this->profiles->parents(),null,'id');$out=[];
        foreach(array_unique($ids) as $id){if(!isset($all[$id]))throw new \InvalidArgumentException('INVALID_SERVERS');$out[$id]=$all[$id];}
        return $out;
    }
    public function status(array $ids): array {
        $parents=$this->parents($ids);$items=[];$proxies=[];
        foreach($this->profiles->proxies() as $row){$id=(int)$row['id'];$proxies[]=['id'=>$id,'name'=>$row['server_name'],'doc'=>$this->profiles->document($id),'status'=>$this->profiles->status($id)];}
        foreach($parents as $id=>$parent) {
            $bindings=[];$pending=false;
            foreach($proxies as $proxy) {
                $pid=$proxy['id'];$doc=$proxy['doc'];
                $intent=in_array($id,$doc['enable_parents']??[],true);$pending=$pending||$intent;
                if($intent||in_array($id,$doc['profile']['parent_ids']??[],true))$bindings[]=['id'=>$pid,'name'=>$proxy['name'],'status'=>$proxy['status'],'enabled'=>(bool)($doc['profile']['enabled']??false)];
            }
            $healthy=array_filter($bindings,fn($b)=>$b['status']['state']==='applied'&&$b['enabled']);
            $items[]=['id'=>$id,'enabled'=>(bool)$parent['enable_proxy'],'state'=>$pending?'pending':((bool)$parent['enable_proxy']?($healthy?'applied':'unavailable'):'disabled'),'proxies'=>$bindings];
        }
        return ['servers'=>$items,'state'=>count(array_filter($items,fn($s)=>$s['state']==='pending'))?'pending':(count(array_filter($items,fn($s)=>$s['state']==='unavailable'))?'unavailable':'applied')];
    }
    public function change(array $ids,bool $enable,?int $selected,array $actor): array {
        $lock=fopen($this->profiles->directory().'/server-control.lock','c');if(!$lock)throw new \RuntimeException('PROFILE_STORAGE_FAILED');chmod($this->profiles->directory().'/server-control.lock',0600);flock($lock,LOCK_EX);
        try{return $this->apply($ids,$enable,$selected,$actor);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function apply(array $ids,bool $enable,?int $selected,array $actor): array {
        $parents=$this->parents($ids);
        if(!$enable){$this->profiles->disableParents(array_keys($parents));return $this->status($ids);}
        $candidates=[];
        foreach($this->profiles->proxies() as $row){$doc=$this->profiles->document((int)$row['id']);if(!empty($doc['profile']['enabled']))$candidates[(int)$row['id']]=['row'=>$row,'doc'=>$doc];}
        if(!$candidates)throw new \RuntimeException('NO_ENABLED_PROXY');
        if($selected!==null&&!isset($candidates[$selected]))throw new \InvalidArgumentException('INVALID_PROXY');
        $plans=[];
        foreach($parents as $id=>$parent) {
            $attached=array_keys(array_filter($candidates,fn($v)=>in_array($id,$v['doc']['profile']['parent_ids'],true)));
            $chosen=$selected!==null?[$selected]:($attached?:array_keys($candidates));
            if(!$attached&&$selected===null&&count($chosen)>1)return ['state'=>'choose_proxy','candidates'=>array_values(array_map(fn($v)=>['id'=>(int)$v['row']['id'],'name'=>$v['row']['server_name']],$candidates))];
            foreach($chosen as $pid) {
                $plans[$pid]??=['doc'=>$candidates[$pid]['doc'],'profile'=>$candidates[$pid]['doc']['profile'],'parents'=>[]];
                $plan=&$plans[$pid];$plan['parents'][]=$id;
                if(!in_array($id,$plan['profile']['parent_ids'],true))$plan['profile']['parent_ids'][]=$id;
                $plan['profile']=$this->tls?($this->tls)($parent,$plan['profile']):ProxyUpstreamTls::prepare($parent,$plan['profile'],CONFIG_PATH.'horus_proxy_upstreams');
                unset($plan);
            }
        }
        // Validate the complete batch before any desired profile is changed.
        foreach($plans as $plan)ProxyProfile::normalize($plan['profile'],array_column($this->profiles->parents(),'id'));
        foreach($plans as $pid=>$plan)$this->profiles->save($pid,$plan['profile'],$plan['doc']['revision'],$actor,'server_enable',$plan['parents']);
        return $this->status($ids);
    }
}
